<?php
declare(strict_types=1);

namespace Venuno\OrderImport\Model\Sync;

/**
 * Magento-free orchestration of the sync for already-imported orders, keyed by the legacy entity_id.
 * Shared verbatim by the CLI, the REST endpoint, the real-database tests and read-only dry runs.
 */
class OrderSyncEngine
{
    public const LEDGER = 'venuno_order_import';

    /**
     * @param \Closure(int):void $refreshGrids
     * @param list<string> $changelogs
     */
    public function __construct(
        private readonly SqlConnection $dest,
        private readonly ?PdoSqlConnection $legacy,
        private readonly SyncContext $context,
        private readonly \Closure $refreshGrids,
        private readonly ?string $baseUrl = null,
        private readonly array $changelogs = SyncApplier::DEFAULT_CHANGELOGS,
        private readonly OrderSnapshotReader $reader = new OrderSnapshotReader(),
        private readonly SyncPlanner $planner = new SyncPlanner()
    ) {
    }

    /** The single imported ledger row for a legacy order. @throws SyncException */
    public function ledger(int $legacyOrderId): array
    {
        $rows = $this->dest->fetchAll(
            'SELECT entity_id, replay_key, source_base_url, source_order_entity_id, source_order_increment_id, magento_order_id, import_status, created_at
               FROM ' . $this->dest->table(self::LEDGER) . ' WHERE source_order_entity_id = ?',
            [(string) $legacyOrderId]
        );
        if ($this->baseUrl !== null) {
            $rows = array_values(array_filter($rows, fn ($r) => self::normaliseUrl((string) $r['source_base_url']) === self::normaliseUrl($this->baseUrl)));
        }
        $imported = array_values(array_filter($rows, fn ($r) => $r['import_status'] === 'imported' && (int) $r['magento_order_id'] > 0));
        if (count($imported) === 0) {
            throw new SyncException('Legacy order ' . $legacyOrderId . ' has no imported destination order.', 'not_imported');
        }
        if (count($imported) > 1) {
            throw new SyncException('Legacy order ' . $legacyOrderId . ' maps to more than one destination order.', 'ledger_ambiguous');
        }
        return $imported[0];
    }

    public function sync(int $legacyOrderId, bool $apply, string $batchId, string $mode, ?array $legacySnapshot = null, ?string $payloadHash = null): SyncOutcome
    {
        $plan = null;
        $ledger = [];
        try {
            $ledger = $this->ledger($legacyOrderId);
            if ($legacySnapshot === null) {
                if ($this->legacy === null) {
                    throw new SyncException('No legacy source is configured.', 'legacy_unavailable');
                }
                $legacySnapshot = $this->reader->readConsistent($this->legacy, $legacyOrderId);
            }
            if ($legacySnapshot === null) {
                throw new SyncException('Legacy order not found.', 'legacy_missing');
            }
            if ((string) $legacySnapshot['order']['entity_id'] !== (string) $legacyOrderId) {
                throw new SyncException('Legacy snapshot identity differs.', 'identity_mismatch');
            }
            $dest = $this->reader->read($this->dest, (int) $ledger['magento_order_id']);
            if ($dest === null) {
                throw new SyncException('Destination order is missing.', 'destination_missing');
            }
            $plan = $this->planner->plan($legacySnapshot, $dest, $this->context, $legacyOrderId);
            if ($plan->isEmpty()) {
                return new SyncOutcome($legacyOrderId, 'noop', $plan);
            }
            if (!$apply) {
                return new SyncOutcome($legacyOrderId, 'planned', $plan);
            }
            $legacy = $this->legacy;
            $fresh = $legacy !== null && $payloadHash === null
                ? fn (): ?string => $this->reader->currentUpdatedAt($legacy, $legacyOrderId)
                : null;
            $auditId = (new SyncApplier($this->reader, $this->planner))->apply(
                $plan, $legacySnapshot, $this->dest, $this->context, $fresh, $this->refreshGrids, $batchId, $mode, $payloadHash, $this->changelogs
            );
            return new SyncOutcome($legacyOrderId, 'applied', $plan, null, null, false, $auditId);
        } catch (SyncException $e) {
            return new SyncOutcome($legacyOrderId, 'failed', $plan, $e->getReason(), $e->getMessage(), $e->isRetryable(), null,
                (int) ($ledger['magento_order_id'] ?? 0), (string) ($ledger['source_order_increment_id'] ?? ''));
        }
    }

    public function rollback(int $auditId): void
    {
        (new SyncRollback())->rollback($this->dest, $auditId, $this->refreshGrids, $this->changelogs);
    }

    /** @return list<int> audit ids of a batch still in applied state, newest first */
    public function auditIdsForBatch(string $batchId): array
    {
        return array_map('intval', array_column($this->dest->fetchAll(
            'SELECT audit_id FROM ' . $this->dest->table(SyncApplier::AUDIT_TABLE) . " WHERE batch_id = ? AND status = 'applied' ORDER BY audit_id DESC",
            [$batchId]
        ), 'audit_id'));
    }

    /**
     * Read-only discovery of imported orders whose legacy row moved on after import: legacy updated_at is
     * later than the ledger row's creation and differs from the destination order's updated_at.
     *
     * @return list<int> legacy entity ids
     */
    public function discover(): array
    {
        if ($this->legacy === null) {
            throw new SyncException('No legacy source is configured.', 'legacy_unavailable');
        }
        $since = $this->dest->fetchOne('SELECT MIN(created_at) FROM ' . $this->dest->table(self::LEDGER) . " WHERE import_status = 'imported'");
        if (!$since) {
            return [];
        }
        $legacyRows = $this->legacy->fetchAll('SELECT entity_id, updated_at FROM ' . $this->legacy->table('sales_order') . ' WHERE updated_at > ?', [$since]);
        $legacyUpdated = array_column($legacyRows, 'updated_at', 'entity_id');
        $found = [];
        foreach (array_chunk(array_keys($legacyUpdated), 1000) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $rows = $this->dest->fetchAll(
                'SELECT v.source_order_entity_id, v.source_base_url, v.created_at, o.updated_at FROM ' . $this->dest->table(self::LEDGER) . ' v
                   JOIN ' . $this->dest->table('sales_order') . " o ON o.entity_id = v.magento_order_id
                  WHERE v.import_status = 'imported' AND v.source_order_entity_id IN ($in)",
                array_map('strval', $chunk)
            );
            foreach ($rows as $row) {
                if ($this->baseUrl !== null && self::normaliseUrl((string) $row['source_base_url']) !== self::normaliseUrl($this->baseUrl)) {
                    continue;
                }
                $legacyAt = $legacyUpdated[(int) $row['source_order_entity_id']] ?? null;
                if ($legacyAt !== null && $legacyAt > $row['created_at'] && $legacyAt !== $row['updated_at']) {
                    $found[(int) $row['source_order_entity_id']] = true;
                }
            }
        }
        $ids = array_keys($found);
        sort($ids);
        return $ids;
    }

    public static function normaliseUrl(string $url): string
    {
        return rtrim(strtolower(trim($url)), '/');
    }
}
