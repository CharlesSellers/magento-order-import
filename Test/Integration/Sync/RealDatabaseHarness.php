<?php
declare(strict_types=1);

namespace Venuno\OrderImport\Test\Integration\Sync;

use Venuno\OrderImport\Model\Sync\PdoSqlConnection;

/**
 * Builds a throw-away legacy schema and destination schema on a real MySQL/MariaDB server from a
 * read-only production export (DDL via SHOW CREATE TABLE incl. exporter change-log triggers, plus the
 * sales rows of the scoped orders). The export contains customer data and is never committed; point
 * VENUNO_SYNC_FIXTURES at it. Foreign keys to tables outside the sales set (store, customer_entity)
 * are dropped; sales-internal foreign keys (document -> order, child -> document) are kept.
 */
final class RealDatabaseHarness
{
    public const LEGACY_DB = 'venuno_sync_test_legacy';
    public const DEST_DB = 'venuno_sync_test_dest';

    public static function available(): ?array
    {
        $dsn = getenv('VENUNO_SYNC_TEST_DSN');
        $fixtures = getenv('VENUNO_SYNC_FIXTURES');
        if (!$dsn || !$fixtures || !is_readable($fixtures)) {
            return null;
        }
        return ['dsn' => $dsn, 'user' => (string) getenv('VENUNO_SYNC_TEST_USER'), 'pass' => (string) getenv('VENUNO_SYNC_TEST_PASS'), 'fixtures' => $fixtures];
    }

    public static function connect(array $env, string $db): \PDO
    {
        $pdo = new \PDO($env['dsn'] . ';dbname=' . $db . ';charset=utf8mb4', $env['user'], $env['pass'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        return $pdo;
    }

    /** @return array{0: PdoSqlConnection, 1: PdoSqlConnection, 2: array} legacy (read-only session), dest, fixture */
    public static function build(array $env): array
    {
        $fixture = json_decode((string) file_get_contents($env['fixtures']), true, 512, JSON_THROW_ON_ERROR);
        $root = new \PDO($env['dsn'] . ';charset=utf8mb4', $env['user'], $env['pass'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        foreach ([self::LEGACY_DB => 'legacy', self::DEST_DB => 'dest'] as $db => $side) {
            $root->exec("DROP DATABASE IF EXISTS `$db`");
            $root->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4");
            $pdo = self::connect($env, $db);
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach ($fixture['ddl'][$side] as $table => $ddl) {
                $pdo->exec(self::portableDdl($ddl, array_keys($fixture['ddl'][$side])));
            }
            if ($side === 'dest') {
                foreach ($fixture['ddl']['triggers'] ?? [] as $trigger) {
                    $pdo->exec($trigger);
                }
                foreach ($fixture['status'] as $row) {
                    self::insert($pdo, 'sales_order_status', $row);
                }
                foreach ($fixture['status_state'] as $row) {
                    self::insert($pdo, 'sales_order_status_state', $row);
                }
            }
            foreach ($fixture['orders'] as $order) {
                self::loadSnapshot($pdo, $order[$side]);
                if ($side === 'dest') {
                    self::insert($pdo, 'venuno_order_import', $order['ledger']);
                }
            }
            if ($side === 'dest') {
                $pdo->exec('DELETE FROM sales_order_data_exporter_cl'); // rows produced by loading are not part of the test
            }
        }
        $legacyPdo = self::connect($env, self::LEGACY_DB);
        $legacyPdo->exec('SET SESSION TRANSACTION READ ONLY');
        return [new PdoSqlConnection($legacyPdo), new PdoSqlConnection(self::connect($env, self::DEST_DB)), $fixture];
    }

    public static function loadSnapshot(\PDO $pdo, array $s): void
    {
        // Synthetic snapshots carry only the columns under test; let NOT NULL columns take implicit defaults while loading.
        $mode = $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
        $pdo->exec("SET SESSION sql_mode = ''");
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        self::insert($pdo, 'sales_order', $s['order']);
        foreach ($s['addresses'] as $address) {
            if ($address !== null) {
                self::insert($pdo, 'sales_order_address', $address);
            }
        }
        foreach ($s['items'] as $item) {
            self::insert($pdo, 'sales_order_item', $item);
        }
        if ($s['payment'] !== null) {
            self::insert($pdo, 'sales_order_payment', $s['payment']);
        }
        foreach (['invoices' => 'sales_invoice', 'shipments' => 'sales_shipment', 'creditmemos' => 'sales_creditmemo'] as $key => $table) {
            foreach ($s[$key] as $doc) {
                self::insert($pdo, $table, array_diff_key($doc, ['items' => 1, 'comments' => 1, 'tracks' => 1]));
                foreach ($doc['items'] as $row) {
                    self::insert($pdo, $table . '_item', $row);
                }
                foreach ($doc['comments'] as $row) {
                    self::insert($pdo, $table . '_comment', $row);
                }
                foreach ($doc['tracks'] ?? [] as $row) {
                    self::insert($pdo, 'sales_shipment_track', $row);
                }
            }
        }
        foreach ($s['status_histories'] as $row) {
            self::insert($pdo, 'sales_order_status_history', $row);
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        $pdo->prepare('SET SESSION sql_mode = ?')->execute([$mode]);
    }

    public static function insert(\PDO $pdo, string $table, array $row): void
    {
        static $columns = [];
        $key = spl_object_id($pdo) . $table;
        $columns[$key] ??= array_flip(array_column($pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(\PDO::FETCH_ASSOC), 'Field'));
        $row = array_intersect_key($row, $columns[$key]);
        $cols = array_keys($row);
        $st = $pdo->prepare("INSERT INTO `$table` (`" . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')');
        $st->execute(array_values($row));
    }

    /** Checksums of every table in a schema (proves a side was not written). */
    public static function checksum(\PDO $pdo): array
    {
        $out = [];
        foreach ($pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN) as $table) {
            $out[$table] = $pdo->query("CHECKSUM TABLE `$table`")->fetch(\PDO::FETCH_ASSOC)['Checksum'];
        }
        return $out;
    }

    private static function portableDdl(string $ddl, array $known): string
    {
        // Drop foreign keys to tables outside the copied sales set.
        $ddl = preg_replace_callback('/,\s*CONSTRAINT `[^`]+` FOREIGN KEY \([^)]*\) REFERENCES `(\w+)` \([^)]*\)( ON DELETE \w+( \w+)?)?( ON UPDATE \w+( \w+)?)?/',
            fn ($m) => in_array($m[1], $known, true) ? $m[0] : '', $ddl);
        return preg_replace('/utf8mb4_0900_ai_ci/', 'utf8mb4_general_ci', $ddl);
    }
}
