<?php
declare(strict_types=1);

namespace Venuno\OrderImport\Model\Sync;

/**
 * Executes one {@see SyncPlan} in a single destination transaction with direct SQL: no order save, so
 * no sales observers, approval requests, emails, payment/refund/shipment services or stock movements.
 *
 * Guards (all fail closed, nothing committed): the destination order is locked and must still match the
 * planned fingerprint; inserted document numbers must still be free; after writing, the destination is
 * re-read and re-planned against the same legacy snapshot and must be an exact match; legacy's
 * updated_at is re-read outside the snapshot and must be unchanged. Exporter change-log rows created by
 * this transaction for the order are removed, grids are refreshed, and an audit row records every
 * before/after value and inserted id for {@see SyncRollback}.
 */
class SyncApplier
{
    public const AUDIT_TABLE = 'venuno_order_sync_audit';
    public const DEFAULT_CHANGELOGS = ['sales_order_data_exporter_cl', 'sales_order_status_data_exporter_cl'];

    public function __construct(
        private readonly OrderSnapshotReader $reader,
        private readonly SyncPlanner $planner
    ) {
    }

    /**
     * @param \Closure():(string|null)|null $legacyUpdatedAt fresh legacy updated_at (null in payload mode)
     * @param \Closure(int):void $refreshGrids
     * @param list<string> $changelogs
     * @return int audit id
     * @throws SyncException
     */
    public function apply(
        SyncPlan $plan,
        array $legacySnapshot,
        SqlConnection $db,
        SyncContext $context,
        ?\Closure $legacyUpdatedAt,
        \Closure $refreshGrids,
        string $batchId,
        string $mode,
        ?string $payloadHash = null,
        array $changelogs = self::DEFAULT_CHANGELOGS
    ): int {
        if ($plan->isEmpty()) {
            throw new \LogicException('Nothing to apply.');
        }
        if ($db->inTransaction()) {
            throw new \LogicException('Order sync must own its transaction.');
        }
        $db->begin();
        try {
            $logs = [];
            foreach ($changelogs as $log) {
                if ($db->tableExists($log)) {
                    $logs[$log] = (int) $db->fetchOne('SELECT COALESCE(MAX(version_id), 0) FROM ' . $db->table($log));
                }
            }
            $locked = $this->reader->read($db, $plan->magentoOrderId, true);
            if ($locked === null || SyncPlanner::fingerprint($locked) !== $plan->destinationFingerprint) {
                throw new SyncException('The destination order changed after planning.', 'destination_changed_during_sync', true);
            }

            foreach ($plan->updates as $update) {
                $sets = [];
                $bind = [];
                foreach ($update['fields'] as $field => $change) {
                    $sets[] = '`' . $this->column($field) . '` = ?';
                    $bind[] = $change['to'];
                }
                if (!isset($update['fields']['updated_at']) && in_array('updated_at', $db->columns($update['table']), true)) {
                    $sets[] = '`updated_at` = `updated_at`'; // suppress ON UPDATE CURRENT_TIMESTAMP; legacy's value is authoritative
                }
                $bind[] = $update['id'];
                $db->execute('UPDATE ' . $db->table($update['table']) . ' SET ' . implode(', ', $sets)
                    . ' WHERE `' . $this->column($update['key']) . '` = ?', $bind);
            }

            $inserted = [];
            $invoiceMap = $plan->invoiceMap;
            $order = $locked['order'];
            $billingId = (int) ($locked['addresses']['billing']['entity_id'] ?? 0) ?: null;
            $shippingId = (int) ($locked['addresses']['shipping']['entity_id'] ?? 0) ?: null;
            $productIds = array_column($locked['items'], 'product_id', 'item_id');
            foreach ($plan->inserts as $insert) {
                $source = $insert['source'];
                switch ($insert['kind']) {
                    case 'invoices':
                    case 'shipments':
                    case 'creditmemos':
                        $kind = substr($insert['kind'], 0, -1);
                        $table = 'sales_' . $kind;
                        if ((int) $db->fetchOne('SELECT COUNT(*) FROM ' . $db->table($table) . ' WHERE store_id = ? AND increment_id = ?',
                            [$plan->storeId, $source['increment_id']]) > 0) {
                            throw new SyncException($table . ' number ' . $source['increment_id'] . ' is already used.', 'history_number_conflict');
                        }
                        $mapped = ['store_id' => $plan->storeId, 'order_id' => $plan->magentoOrderId, 'billing_address_id' => $billingId,
                            'shipping_address_id' => $shippingId, 'send_email' => 0, 'email_sent' => 0];
                        if ($kind === 'shipment') {
                            $mapped['customer_id'] = $order['customer_id'];
                        }
                        if ($kind === 'creditmemo') {
                            $mapped['invoice_id'] = !empty($source['invoice_id']) ? ($invoiceMap[(string) $source['invoice_id']] ?? null) : null;
                            if (!empty($source['invoice_id']) && $mapped['invoice_id'] === null) {
                                throw new SyncException('Credit memo invoice could not be mapped.', 'document_mismatch');
                            }
                        }
                        $id = $this->insertRow($db, $table, $source, SyncFields::contract($insert['kind']), $mapped, ['entity_id', 'customer_id'], $inserted);
                        if ($kind === 'invoice') {
                            $invoiceMap[(string) $source['entity_id']] = $id;
                        }
                        foreach ($source['items'] ?? [] as $child) {
                            $orderItemId = $plan->itemMap[(string) $child['order_item_id']] ?? null;
                            if ($orderItemId === null) {
                                throw new SyncException('Document line could not be mapped to an order line.', 'item_mismatch');
                            }
                            $this->insertRow($db, $table . '_item', $child, SyncFields::contract($kind . '_items'),
                                ['parent_id' => $id, 'order_item_id' => $orderItemId, 'product_id' => $productIds[$orderItemId] ?? null], ['entity_id'], $inserted);
                        }
                        foreach ($source['comments'] ?? [] as $comment) {
                            $this->insertRow($db, $table . '_comment', $comment, SyncFields::contract('comments'), ['parent_id' => $id],
                                ['entity_id', 'status', 'entity_name'], $inserted);
                        }
                        foreach ($kind === 'shipment' ? ($source['tracks'] ?? []) : [] as $track) {
                            $this->insertRow($db, 'sales_shipment_track', $track, SyncFields::contract('tracks'),
                                ['parent_id' => $id, 'order_id' => $plan->magentoOrderId], ['entity_id'], $inserted);
                        }
                        break;
                    case 'tracks':
                        $this->insertRow($db, 'sales_shipment_track', $source, SyncFields::contract('tracks'),
                            ['parent_id' => $insert['parent'], 'order_id' => $plan->magentoOrderId], ['entity_id'], $inserted);
                        break;
                    case 'invoices_comment':
                    case 'shipments_comment':
                    case 'creditmemos_comment':
                        $table = 'sales_' . substr($insert['kind'], 0, -9) . '_comment';
                        $this->insertRow($db, $table, $source, SyncFields::contract('comments'), ['parent_id' => $insert['parent']],
                            ['entity_id', 'status', 'entity_name'], $inserted);
                        break;
                    case 'status_histories':
                        $this->insertRow($db, 'sales_order_status_history', $source, SyncFields::contract('comments'),
                            ['parent_id' => $plan->magentoOrderId], ['entity_id'], $inserted);
                        break;
                    default:
                        throw new \LogicException('Unknown insert kind ' . $insert['kind']);
                }
            }

            $note = sprintf('Venuno sync: matched legacy order %s (entity_id %d, updated %s) [%s]. Batch %s.',
                $plan->incrementId, $plan->legacyOrderId, $plan->legacyUpdatedAt, implode(', ', $plan->categories), $batchId);
            $noteId = $db->insert('sales_order_status_history', ['parent_id' => $plan->magentoOrderId, 'is_customer_notified' => 0,
                'is_visible_on_front' => 0, 'comment' => $note, 'status' => $plan->targetStatus, 'entity_name' => 'order',
                'created_at' => $plan->legacyUpdatedAt !== '' ? $plan->legacyUpdatedAt : null]);
            $inserted[] = ['table' => 'sales_order_status_history', 'id' => $noteId];

            // Read back and re-plan against the same legacy snapshot: must now be an exact match.
            $after = $this->reader->read($db, $plan->magentoOrderId);
            try {
                $residual = $this->planner->plan($legacySnapshot, $after ?? [], $context, $plan->legacyOrderId);
            } catch (SyncException $e) {
                throw new SyncException('Read-back failed: ' . $e->getMessage(), 'readback_mismatch', false, $e);
            }
            if (!$residual->isEmpty()) {
                throw new SyncException('Read-back still differs from legacy: ' . json_encode($residual->summary()['updates']), 'readback_mismatch');
            }

            if ($legacyUpdatedAt !== null) {
                $current = $legacyUpdatedAt();
                if ($current === null || !SyncFields::same($current, $plan->legacyUpdatedAt)) {
                    throw new SyncException('The legacy order changed during the sync.', 'legacy_changed_during_sync', true);
                }
            }

            $refreshGrids($plan->magentoOrderId);
            foreach ($logs as $log => $max) {
                $db->execute('DELETE FROM ' . $db->table($log) . ' WHERE version_id > ? AND entity_id = ?', [$max, $plan->magentoOrderId]);
            }

            $auditId = $db->insert(self::AUDIT_TABLE, [
                'batch_id' => $batchId,
                'mode' => $mode,
                'magento_order_id' => $plan->magentoOrderId,
                'source_order_entity_id' => (string) $plan->legacyOrderId,
                'increment_id' => $plan->incrementId,
                'legacy_updated_at' => $plan->legacyUpdatedAt,
                'payload_hash' => $payloadHash,
                'categories' => implode(',', $plan->categories),
                'changes' => json_encode(['updates' => $plan->updates, 'inserted' => $inserted, 'warnings' => $plan->warnings],
                    JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
                'status' => 'applied',
            ]);
            $db->commit();
            return $auditId;
        } catch (\Throwable $e) {
            $db->rollBack();
            if ($e instanceof SyncException) {
                throw $e;
            }
            throw new SyncException('Sync write failed: ' . $e->getMessage(), 'write_failed', false, $e);
        }
    }

    /** @param list<array{table:string,id:int}> $inserted */
    private function insertRow(SqlConnection $db, string $table, array $source, array $allowed, array $mapped, array $remove, array &$inserted): int
    {
        $data = array_replace(array_diff_key(array_intersect_key($source, array_flip($allowed)), array_flip($remove)), $mapped);
        $columns = array_flip($db->columns($table));
        if (array_diff_key($data, $columns)) {
            throw new SyncException('Destination ' . $table . ' cannot store: ' . implode(',', array_keys(array_diff_key($data, $columns))), 'history_schema_mismatch');
        }
        $id = $db->insert($table, $data);
        $inserted[] = ['table' => $table, 'id' => $id];
        return $id;
    }

    private function column(string $name): string
    {
        if (!preg_match('/^[a-z0-9_]+$/D', $name)) {
            throw new \InvalidArgumentException('Invalid column.');
        }
        return $name;
    }
}
