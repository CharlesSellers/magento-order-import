<?php
declare(strict_types=1);

namespace Venuno\OrderImport\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Status-to-state assignments that legacy Jangro orders use but the destination did not declare, so a
 * synced order can carry legacy's (state, status) exactly (e.g. an order approved on legacy leaves
 * `awaiting_approval` for `approved` while staying in state `new`).
 *
 * Additive only: inserts a missing (status, state) row with is_default=0 and visible_on_front=1 when
 * the status already exists. Never changes defaults, labels or existing assignments. Statuses that do
 * not exist on the destination are skipped (the sync then fails closed with `status_unknown`).
 */
class AddLegacyStatusStateAssignments implements DataPatchInterface
{
    /** Observed on legacy imported orders (status, state). */
    public const PAIRS = [
        ['approved', 'new'],
        ['rejected', 'new'],
        ['approved', 'closed'],
        ['complete', 'processing'],
    ];

    public function __construct(private readonly ModuleDataSetupInterface $setup)
    {
    }

    public function apply(): self
    {
        $db = $this->setup->getConnection();
        $statusTable = $this->setup->getTable('sales_order_status');
        $stateTable = $this->setup->getTable('sales_order_status_state');
        foreach (self::PAIRS as [$status, $state]) {
            $exists = (int) $db->fetchOne($db->select()->from($statusTable, ['COUNT(*)'])->where('status = ?', $status));
            $assigned = (int) $db->fetchOne($db->select()->from($stateTable, ['COUNT(*)'])->where('status = ?', $status)->where('state = ?', $state));
            if ($exists && !$assigned) {
                $db->insert($stateTable, ['status' => $status, 'state' => $state, 'is_default' => 0, 'visible_on_front' => 1]);
            }
        }
        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
