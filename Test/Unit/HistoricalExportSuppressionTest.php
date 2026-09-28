<?php
declare(strict_types=1);
namespace Venuno\OrderImport\Test\Unit;
use PHPUnit\Framework\TestCase;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Venuno\OrderImport\Model\Materialisation\HistoricalExportSuppression;
final class HistoricalExportSuppressionTest extends TestCase
{
    public function testOnlyNewOrderQueueEntriesAreSuppressedInsideTransaction(): void
    {
        $config=$this->createMock(DeploymentConfig::class);$config->method('get')->willReturn(true);
        $resource=$this->createMock(ResourceConnection::class);$db=$this->createMock(AdapterInterface::class);
        $resource->method('getConnection')->willReturn($db);$resource->method('getTableName')->willReturnArgument(0);
        $db->method('getTransactionLevel')->willReturn(1);$db->method('isTableExists')->willReturn(true);
        $db->expects(self::exactly(2))->method('delete')->with(self::callback(static fn($t)=>in_array($t,['sales_order_data_exporter_cl','sales_order_status_data_exporter_cl'],true)),['entity_id = ?'=>123]);
        (new HistoricalExportSuppression($resource,$config))->suppress(123);
    }
    public function testDisabledDoesNotTouchDatabase(): void
    {
        $config=$this->createMock(DeploymentConfig::class);$config->method('get')->willReturn(null);$resource=$this->createMock(ResourceConnection::class);$resource->expects(self::never())->method('getConnection');
        (new HistoricalExportSuppression($resource,$config))->suppress(123);
    }
    public function testMissingTransactionFailsBeforeDelete(): void
    {
        $config=$this->createMock(DeploymentConfig::class);$config->method('get')->willReturn(true);$resource=$this->createMock(ResourceConnection::class);$db=$this->createMock(AdapterInterface::class);$resource->method('getConnection')->willReturn($db);$db->method('getTransactionLevel')->willReturn(0);$db->expects(self::never())->method('delete');
        $this->expectException(\RuntimeException::class);(new HistoricalExportSuppression($resource,$config))->suppress(123);
    }
}
