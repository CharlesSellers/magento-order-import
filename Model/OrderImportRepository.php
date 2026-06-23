<?php
/**
 * Copyright © Venuno. All rights reserved.
 */

declare(strict_types=1);

namespace Venuno\OrderImport\Model;

use Magento\Framework\App\ResourceConnection;

/**
 * Thin persistence for the `venuno_order_import` table: look up a recorded import by its idempotency
 * anchor (`replay_key`) and insert a new staged import. Kept deliberately small (direct, scoped reads
 * and one insert via the resource connection) — there is no rich domain model here, only the
 * idempotency ledger + staging record.
 */
class OrderImportRepository
{
    private const TABLE = 'venuno_order_import';

    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * The recorded import for a replay_key, or null when none exists.
     *
     * @return array<string, mixed>|null
     */
    public function findByReplayKey(string $replayKey): ?array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);
        $select = $connection->select()
            ->from($table)
            ->where('replay_key = ?', $replayKey)
            ->limit(1);
        $row = $connection->fetchRow($select);

        return $row === false ? null : $row;
    }

    /**
     * Insert a staged import row. Returns the new row id.
     *
     * @param array<string, mixed> $row
     */
    public function insert(array $row): int
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);
        $connection->insert($table, $row);

        return (int) $connection->lastInsertId($table);
    }
}
