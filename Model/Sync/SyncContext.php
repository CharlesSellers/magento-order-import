<?php
declare(strict_types=1);

namespace Venuno\OrderImport\Model\Sync;

/** Destination facts the pure planner needs: status/state assignments and document-number ownership. */
final class SyncContext
{
    /**
     * @param array<string, array<string, true>> $pairs status => [state => true] from sales_order_status_state
     * @param array<string, true> $statuses known destination statuses
     * @param \Closure(string $table, int $storeId, string $incrementId, int $magentoOrderId): bool $numberTakenElsewhere
     * @param array<string, array<string, true>> $pendingPairs pairs the module's data patch adds (dry-run before deploy)
     */
    public function __construct(
        public readonly array $pairs,
        public readonly array $statuses,
        public readonly \Closure $numberTakenElsewhere,
        public readonly array $pendingPairs = []
    ) {
    }

    public static function load(SqlConnection $db, bool $includePatchPairs = false): self
    {
        $pairs = [];
        foreach ($db->fetchAll('SELECT status, state FROM ' . $db->table('sales_order_status_state')) as $row) {
            $pairs[(string) $row['status']][(string) $row['state']] = true;
        }
        $statuses = [];
        foreach ($db->fetchAll('SELECT status FROM ' . $db->table('sales_order_status')) as $row) {
            $statuses[(string) $row['status']] = true;
        }
        $pending = [];
        if ($includePatchPairs) {
            foreach (\Venuno\OrderImport\Setup\Patch\Data\AddLegacyStatusStateAssignments::PAIRS as [$status, $state]) {
                if (isset($statuses[$status]) && !isset($pairs[$status][$state])) {
                    $pending[$status][$state] = true;
                }
            }
        }
        $taken = static function (string $table, int $storeId, string $incrementId, int $magentoOrderId) use ($db): bool {
            return (int) $db->fetchOne(
                'SELECT COUNT(*) FROM ' . $db->table($table) . ' WHERE store_id = ? AND increment_id = ? AND order_id <> ?',
                [$storeId, $incrementId, $magentoOrderId]
            ) > 0;
        };
        return new self($pairs, $statuses, \Closure::fromCallable($taken), $pending);
    }
}
