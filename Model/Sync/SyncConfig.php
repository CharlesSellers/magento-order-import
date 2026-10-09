<?php
declare(strict_types=1);

namespace Venuno\OrderImport\Model\Sync;

use Magento\Framework\App\DeploymentConfig;

/**
 * `venuno/order_import/order_sync` in app/etc/env.php (all optional, default off):
 *
 *     'order_sync' => [
 *         'enabled'      => true,             // CLI + API available
 *         'database'     => 'LEGACY_SCHEMA',  // read-only legacy source on the same MySQL server (preferred)
 *         'base_url'     => 'https://legacy.example/', // must match the ledger's source_base_url
 *         'api_apply'    => false,            // API plans only (dry run) until explicitly enabled
 *         'changelogs'   => ['sales_order_data_exporter_cl', 'sales_order_status_data_exporter_cl'],
 *     ],
 */
class SyncConfig
{
    public const PATH = 'venuno/order_import/order_sync';

    public function __construct(private readonly DeploymentConfig $deploymentConfig)
    {
    }

    private function get(): array
    {
        $value = $this->deploymentConfig->get(self::PATH);
        return is_array($value) ? $value : [];
    }

    public function isEnabled(): bool
    {
        return ($this->get()['enabled'] ?? false) === true;
    }

    public function legacyDatabase(): ?string
    {
        $db = $this->get()['database'] ?? null;
        $default = $this->deploymentConfig->get('db/connection/default/dbname');
        if (!is_string($db) || !preg_match('/^[A-Za-z0-9_]+$/D', $db) || $db === $default) {
            return null;
        }
        return $db;
    }

    public function baseUrl(): ?string
    {
        $url = $this->get()['base_url'] ?? null;
        return is_string($url) && $url !== '' ? $url : null;
    }

    public function apiMayApply(): bool
    {
        return ($this->get()['api_apply'] ?? false) === true;
    }

    /** @return list<string> */
    public function changelogs(): array
    {
        $logs = $this->get()['changelogs'] ?? SyncApplier::DEFAULT_CHANGELOGS;
        return array_values(array_filter((array) $logs, fn ($t) => is_string($t) && preg_match('/^[a-z0-9_]+_cl$/D', $t)));
    }

    public function destinationDbConfig(): array
    {
        return (array) $this->deploymentConfig->get('db/connection/default');
    }
}
