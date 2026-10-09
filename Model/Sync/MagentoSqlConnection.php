<?php
declare(strict_types=1);

namespace Venuno\OrderImport\Model\Sync;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * {@see SqlConnection} over Magento's default resource connection, so sync writes share Magento's
 * transaction bookkeeping (and the grid refresh runs on the same connection, inside the transaction).
 */
class MagentoSqlConnection implements SqlConnection
{
    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    private function db(): AdapterInterface
    {
        return $this->resource->getConnection();
    }

    public function fetchAll(string $sql, array $bind = []): array
    {
        return $this->db()->fetchAll($sql, $bind);
    }

    public function fetchRow(string $sql, array $bind = []): ?array
    {
        $row = $this->db()->fetchRow($sql, $bind);
        return is_array($row) ? $row : null;
    }

    public function fetchOne(string $sql, array $bind = []): mixed
    {
        return $this->db()->fetchOne($sql, $bind);
    }

    public function execute(string $sql, array $bind = []): int
    {
        return $this->db()->query($sql, $bind)->rowCount();
    }

    public function insert(string $table, array $row): int
    {
        $db = $this->db();
        $db->insert($this->resource->getTableName($table), $row);
        return (int) $db->lastInsertId($this->resource->getTableName($table));
    }

    public function columns(string $table): array
    {
        return array_keys($this->db()->describeTable($this->resource->getTableName($table)));
    }

    public function tableExists(string $table): bool
    {
        return $this->db()->isTableExists($this->resource->getTableName($table));
    }

    public function table(string $table): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/D', $table)) {
            throw new \InvalidArgumentException('Invalid table name.');
        }
        return '`' . $this->resource->getTableName($table) . '`';
    }

    public function begin(): void
    {
        $this->db()->beginTransaction();
    }

    public function commit(): void
    {
        $this->db()->commit();
    }

    public function rollBack(): void
    {
        if ($this->db()->getTransactionLevel() > 0) {
            $this->db()->rollBack();
        }
    }

    public function inTransaction(): bool
    {
        return $this->db()->getTransactionLevel() > 0;
    }

    public function sessionTimeZone(): string
    {
        return (string) $this->db()->fetchOne('SELECT @@session.time_zone');
    }
}
