<?php
declare(strict_types=1);

namespace Venuno\OrderImport\Model\Sync;

use Magento\Sales\Model\ResourceModel\GridPool;

/** Magento wiring for {@see OrderSyncEngine}: destination connection, grid refresh, protected config. */
class OrderSyncService
{
    public function __construct(
        private readonly MagentoSqlConnection $dest,
        private readonly GridPool $grids,
        private readonly SyncConfig $config
    ) {
    }

    public function config(): SyncConfig
    {
        return $this->config;
    }

    /** @throws SyncException when the sync is disabled */
    public function engine(bool $requireLegacy = true): OrderSyncEngine
    {
        if (!$this->config->isEnabled()) {
            throw new SyncException('Order sync is disabled (venuno/order_import/order_sync.enabled).', 'sync_disabled');
        }
        $legacy = null;
        $database = $this->config->legacyDatabase();
        if ($database !== null) {
            $legacy = PdoSqlConnection::readOnly($this->config->destinationDbConfig(), $database, $this->dest->sessionTimeZone());
        } elseif ($requireLegacy) {
            throw new SyncException('No read-only legacy database is configured.', 'legacy_unavailable');
        }
        $grids = $this->grids;
        return new OrderSyncEngine(
            $this->dest,
            $legacy,
            SyncContext::load($this->dest),
            static function (int $orderId) use ($grids): void {
                $grids->refreshByOrderId($orderId);
            },
            $this->config->baseUrl(),
            $this->config->changelogs()
        );
    }
}
