<?php
declare(strict_types=1);

namespace Venuno\OrderImport\Model\Sync;

/** {@see SqlConnection} over a plain PDO handle (legacy read-only source, real-database tests). */
final class PdoSqlConnection implements SqlConnection
{
    /** @var array<string, list<string>> */
    private array $columnCache = [];

    public function __construct(private readonly \PDO $pdo, private readonly string $prefix = '')
    {
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
    }

    /**
     * Opens a dedicated connection to $database on the destination's MySQL server, in a READ ONLY
     * session. Uses the same session time zone as the destination so TIMESTAMP values round-trip.
     */
    public static function readOnly(array $dbConfig, string $database, string $timeZone): self
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/D', $database)) {
            throw new \InvalidArgumentException('Invalid legacy database name.');
        }
        [$host, $port] = array_pad(explode(':', (string) ($dbConfig['host'] ?? 'localhost'), 2), 2, null);
        $dsn = 'mysql:host=' . $host . ($port !== null && ctype_digit($port) ? ';port=' . $port : '')
            . ';dbname=' . $database . ';charset=utf8mb4';
        $pdo = new \PDO($dsn, (string) $dbConfig['username'], (string) ($dbConfig['password'] ?? ''), [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_EMULATE_PREPARES => false,
            \PDO::ATTR_TIMEOUT => 10,
            \PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
        ]);
        $pdo->exec('SET SESSION TRANSACTION READ ONLY');
        $tz = $pdo->prepare('SET time_zone = ?');
        $tz->execute([$timeZone]);
        return new self($pdo);
    }

    public function pdo(): \PDO
    {
        return $this->pdo;
    }

    public function fetchAll(string $sql, array $bind = []): array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($bind);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function fetchRow(string $sql, array $bind = []): ?array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($bind);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function fetchOne(string $sql, array $bind = []): mixed
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($bind);
        return $st->fetchColumn();
    }

    public function execute(string $sql, array $bind = []): int
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($bind);
        return $st->rowCount();
    }

    public function insert(string $table, array $row): int
    {
        $cols = array_keys($row);
        $sql = 'INSERT INTO ' . $this->table($table) . ' (' . implode(',', array_map(fn ($c) => '`' . $c . '`', $cols))
            . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
        $this->execute($sql, array_values($row));
        return (int) $this->pdo->lastInsertId();
    }

    public function columns(string $table): array
    {
        return $this->columnCache[$table] ??= array_column(
            $this->fetchAll('SHOW COLUMNS FROM ' . $this->table($table)),
            'Field'
        );
    }

    public function tableExists(string $table): bool
    {
        return (int) $this->fetchOne(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$this->prefix . $table]
        ) > 0;
    }

    public function table(string $table): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/D', $table)) {
            throw new \InvalidArgumentException('Invalid table name.');
        }
        return '`' . $this->prefix . $table . '`';
    }

    public function begin(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }
}
