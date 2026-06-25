<?php
/**
 * Copyright © Venuno. All rights reserved.
 */

declare(strict_types=1);

namespace Venuno\OrderImport\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;
use Venuno\OrderImport\Model\Materialisation\ImportRowStoreInterface;

/**
 * Persistence for the `venuno_order_import` table: the idempotency ledger (lookup + insert) plus the
 * materialisation state transitions ({@see ImportRowStoreInterface}). Kept deliberately small — direct,
 * scoped reads and writes via the resource connection; no rich domain model.
 */
class OrderImportRepository implements ImportRowStoreInterface
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

    /** {@see ImportRowStoreInterface::find()} — alias of {@see findByReplayKey()}. */
    public function find(string $replayKey): ?array
    {
        return $this->findByReplayKey($replayKey);
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

    /**
     * Optimistic claim: transition `pending|failed → materialising` in one atomic UPDATE and bump the
     * attempt counter. Exactly one concurrent caller sees an affected-row count of 1 and owns the
     * materialisation; everyone else (including an already-`imported`/`materialising` row) sees 0.
     */
    public function claimForMaterialisation(string $replayKey): bool
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);
        $affected = $connection->update(
            $table,
            [
                'import_status' => 'materialising',
                'attempts' => new Expression('attempts + 1'),
            ],
            [
                'replay_key = ?' => $replayKey,
                'import_status IN (?)' => ['pending', 'failed'],
            ]
        );

        return $affected === 1;
    }

    public function markImported(string $replayKey, int $magentoOrderId): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);
        $connection->update(
            $table,
            [
                'import_status' => 'imported',
                'magento_order_id' => $magentoOrderId,
                'materialised_at' => new Expression('NOW()'),
                'error_message' => null,
            ],
            ['replay_key = ?' => $replayKey]
        );
    }

    public function markFailed(string $replayKey, string $error): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);
        $connection->update(
            $table,
            [
                'import_status' => 'failed',
                // Keep the message within the TEXT column's practical bounds.
                'error_message' => mb_substr($error, 0, 2000),
            ],
            ['replay_key = ?' => $replayKey]
        );
    }
}
