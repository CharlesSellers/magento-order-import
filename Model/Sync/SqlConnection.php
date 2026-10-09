<?php
declare(strict_types=1);

namespace Venuno\OrderImport\Model\Sync;

/**
 * Minimal SQL seam for the order-sync engine. One implementation wraps Magento's resource connection
 * (destination writes share Magento's connection and transaction level); the other wraps a plain PDO
 * (the read-only legacy source, and real-database tests). Table names passed in are logical names.
 */
interface SqlConnection
{
    /** @return list<array<string, mixed>> */
    public function fetchAll(string $sql, array $bind = []): array;

    /** @return array<string, mixed>|null */
    public function fetchRow(string $sql, array $bind = []): ?array;

    public function fetchOne(string $sql, array $bind = []): mixed;

    /** Executes a statement and returns the affected-row count. */
    public function execute(string $sql, array $bind = []): int;

    /** Inserts one row and returns its auto-increment id. */
    public function insert(string $table, array $row): int;

    /** @return list<string> column names of a (logical) table */
    public function columns(string $table): array;

    public function tableExists(string $table): bool;

    /** Physical, quoted table name for a logical table name. */
    public function table(string $table): string;

    public function begin(): void;

    public function commit(): void;

    public function rollBack(): void;

    public function inTransaction(): bool;
}
