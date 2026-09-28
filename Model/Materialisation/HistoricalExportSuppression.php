<?php
declare(strict_types=1);
namespace Venuno\OrderImport\Model\Materialisation;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\DeploymentConfig;

/** Prevent a newly archived order being treated as a fresh outbound sale. No global queue changes. */
class HistoricalExportSuppression
{
    public function __construct(private readonly ResourceConnection $resource, private readonly DeploymentConfig $config) {}
    public function suppress(int $newOrderId): void
    {
        if ($this->config->get('venuno/order_import/suppress_history_export_changelog')!==true) { return; }
        $db=$this->resource->getConnection();
        if ($newOrderId<1 || $db->getTransactionLevel()<1) {
            throw new \RuntimeException('Historical export suppression requires the import transaction.');
        }
        foreach (['sales_order_data_exporter_cl','sales_order_status_data_exporter_cl'] as $name) {
            $table=$this->resource->getTableName($name);
            if ($db->isTableExists($table)) { $db->delete($table,['entity_id = ?'=>$newOrderId]); }
        }
    }
}
