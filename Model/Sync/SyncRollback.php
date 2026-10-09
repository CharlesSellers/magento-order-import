<?php
declare(strict_types=1);

namespace Venuno\OrderImport\Model\Sync;

/**
 * Reverses one applied sync from its audit row: restores every "from" value and deletes every inserted
 * row (children first). Fails closed, changing nothing, if any synced field no longer holds the value
 * the sync wrote (someone or something has changed the order since).
 */
class SyncRollback
{
    private const GRIDS = ['sales_invoice' => 'sales_invoice_grid', 'sales_shipment' => 'sales_shipment_grid', 'sales_creditmemo' => 'sales_creditmemo_grid'];

    /** @param \Closure(int):void $refreshGrids */
    public function rollback(SqlConnection $db, int $auditId, \Closure $refreshGrids, array $changelogs = SyncApplier::DEFAULT_CHANGELOGS): void
    {
        $db->begin();
        try {
            $logs = [];
            foreach ($changelogs as $log) {
                if ($db->tableExists($log)) {
                    $logs[$log] = (int) $db->fetchOne('SELECT COALESCE(MAX(version_id), 0) FROM ' . $db->table($log));
                }
            }
            $audit = $db->fetchRow('SELECT * FROM ' . $db->table(SyncApplier::AUDIT_TABLE) . ' WHERE audit_id = ? FOR UPDATE', [$auditId]);
            if ($audit === null || $audit['status'] !== 'applied') {
                throw new SyncException('Audit row is missing or not in applied state.', 'rollback_not_applicable');
            }
            $orderId = (int) $audit['magento_order_id'];
            $db->fetchOne('SELECT entity_id FROM ' . $db->table('sales_order') . ' WHERE entity_id = ? FOR UPDATE', [$orderId]);
            $changes = json_decode((string) $audit['changes'], true, 512, JSON_THROW_ON_ERROR);
            foreach (array_reverse($changes['updates']) as $update) {
                $key = $update['key'];
                $current = $db->fetchRow('SELECT * FROM ' . $db->table($update['table']) . ' WHERE `' . $key . '` = ?', [$update['id']]);
                if ($current === null) {
                    throw new SyncException('Synced row no longer exists.', 'changed_since_sync');
                }
                $sets = [];
                $bind = [];
                foreach ($update['fields'] as $field => $change) {
                    if (!SyncFields::same($current[$field] ?? null, $change['to'])) {
                        throw new SyncException(sprintf('%s.%s changed since the sync.', $update['table'], $field), 'changed_since_sync');
                    }
                    $sets[] = '`' . $field . '` = ?';
                    $bind[] = $change['from'];
                }
                if (!isset($update['fields']['updated_at']) && array_key_exists('updated_at', $current)) {
                    $sets[] = '`updated_at` = `updated_at`';
                }
                $bind[] = $update['id'];
                $db->execute('UPDATE ' . $db->table($update['table']) . ' SET ' . implode(', ', $sets) . ' WHERE `' . $key . '` = ?', $bind);
            }
            foreach (array_reverse($changes['inserted']) as $row) {
                $pk = 'entity_id';
                $deleted = $db->execute('DELETE FROM ' . $db->table($row['table']) . ' WHERE `' . $pk . '` = ?', [$row['id']]);
                if ($deleted !== 1) {
                    throw new SyncException($row['table'] . ' #' . $row['id'] . ' is already gone.', 'changed_since_sync');
                }
                if (isset(self::GRIDS[$row['table']]) && $db->tableExists(self::GRIDS[$row['table']])) {
                    $db->execute('DELETE FROM ' . $db->table(self::GRIDS[$row['table']]) . ' WHERE entity_id = ?', [$row['id']]);
                }
            }
            $refreshGrids($orderId);
            foreach ($logs as $log => $max) {
                $db->execute('DELETE FROM ' . $db->table($log) . ' WHERE version_id > ? AND entity_id = ?', [$max, $orderId]);
            }
            $db->execute('UPDATE ' . $db->table(SyncApplier::AUDIT_TABLE) . " SET status = 'rolled_back', rolled_back_at = CURRENT_TIMESTAMP WHERE audit_id = ?", [$auditId]);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e instanceof SyncException ? $e : new SyncException('Rollback failed: ' . $e->getMessage(), 'write_failed', false, $e);
        }
    }
}
