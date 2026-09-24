<?php
declare(strict_types=1);

namespace Venuno\OrderImport\Test\Unit\Materialisation;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\ResourceModel\Grid;
use PHPUnit\Framework\TestCase;
use Venuno\OrderImport\Model\Materialisation\MaterialisationException;
use Venuno\OrderImport\Model\Materialisation\OrderDraftBuilder;
use Venuno\OrderImport\Model\Materialisation\SourceMetadataPersistence;
use Venuno\OrderImport\Model\Materialisation\SourceOrderMetadata;

final class SourceMetadataPersistenceTest extends TestCase
{
    private AdapterInterface $db;
    private Grid $grid;
    private SourceMetadataPersistence $persistence;

    protected function setUp(): void
    {
        $this->db = $this->createMock(AdapterInterface::class);
        $select = $this->createMock(Select::class);
        foreach (['from', 'where', 'limit'] as $method) {
            $select->method($method)->willReturnSelf();
        }
        $this->db->method('select')->willReturn($select);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->db);
        $resource->method('getTableName')->willReturnCallback(static fn ($name) => $name);
        $this->grid = $this->createMock(Grid::class);
        $this->persistence = new SourceMetadataPersistence($resource, $this->grid);
    }

    public function testChecksMappingAndOrderNumberWithoutWriting(): void
    {
        $this->db->method('getTransactionLevel')->willReturn(1);
        $this->db->expects(self::exactly(2))->method('fetchOne')->willReturnOnConsecutiveCalls('processing', false);
        $this->db->expects(self::never())->method('update');
        $draft = (new OrderDraftBuilder())->fromImportRow(SourceOrderMetadataTest::row());
        $this->persistence->assertAvailable($draft, SourceOrderMetadata::fromDraft($draft));
    }

    public function testUnmappedStatusIsTerminal(): void
    {
        $this->db->expects(self::once())->method('fetchOne')->willReturn(false);
        $this->assertPreflightReason(MaterialisationException::REASON_SOURCE_STATUS_UNMAPPED);
    }

    public function testCollisionIsTerminalAndDoesNotLinkExistingOrder(): void
    {
        $this->db->method('fetchOne')->willReturnOnConsecutiveCalls('processing', 567);
        $this->assertPreflightReason(MaterialisationException::REASON_ORDER_NUMBER_CONFLICT);
    }

    private function assertPreflightReason(string $reason): void
    {
        $this->db->method('getTransactionLevel')->willReturn(1);
        $draft = (new OrderDraftBuilder())->fromImportRow(SourceOrderMetadataTest::row());
        try {
            $this->persistence->assertAvailable($draft, SourceOrderMetadata::fromDraft($draft));
            self::fail('Expected terminal preflight failure');
        } catch (MaterialisationException $e) {
            self::assertSame($reason, $e->getReason());
            self::assertFalse($e->isRetryable());
        }
    }

    public function testDatesAreRestoredAndReadBackBeforeTargetedGridRefresh(): void
    {
        $draft = (new OrderDraftBuilder())->fromImportRow(SourceOrderMetadataTest::row());
        $metadata = SourceOrderMetadata::fromDraft($draft);
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn(567);
        $order->expects(self::once())->method('setCreatedAt')->with($metadata->createdAt);
        $order->expects(self::once())->method('setUpdatedAt')->with($metadata->updatedAt);
        $this->db->method('getTransactionLevel')->willReturn(1);
        $dates = ['created_at' => $metadata->createdAt, 'updated_at' => $metadata->updatedAt];
        $this->db->method('fetchRow')->willReturnOnConsecutiveCalls([
            'store_id' => '4', 'increment_id' => '00000123', 'state' => 'processing', 'status' => 'processing',
        ], $dates);
        $this->db->expects(self::once())->method('update')->with('sales_order', $dates, ['entity_id = ?' => 567]);
        $this->grid->expects(self::once())->method('refresh')->with(567);
        $this->persistence->finish($order, $draft, $metadata);
    }

    public function testNoTransactionRefusesDateWrite(): void
    {
        $draft = (new OrderDraftBuilder())->fromImportRow(SourceOrderMetadataTest::row());
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn(567);
        $this->db->method('getTransactionLevel')->willReturn(0);
        $this->db->expects(self::never())->method('update');
        $this->expectException(\RuntimeException::class);
        $this->persistence->finish($order, $draft, SourceOrderMetadata::fromDraft($draft));
    }

    public function testPreflightRequiresTransactionBeforeOrderCreation(): void
    {
        $draft = (new OrderDraftBuilder())->fromImportRow(SourceOrderMetadataTest::row());
        $this->db->method('getTransactionLevel')->willReturn(0);
        $this->db->expects(self::never())->method('fetchOne');
        $this->expectException(\RuntimeException::class);
        $this->persistence->assertAvailable($draft, SourceOrderMetadata::fromDraft($draft));
    }

    public function testChangedStateRollsBackRatherThanSilentlyRewritingIt(): void
    {
        $draft = (new OrderDraftBuilder())->fromImportRow(SourceOrderMetadataTest::row());
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn(567);
        $this->db->method('getTransactionLevel')->willReturn(1);
        $this->db->method('fetchRow')->willReturn([
            'store_id' => '4', 'increment_id' => '00000123', 'state' => 'complete', 'status' => 'complete',
        ]);
        $this->db->expects(self::never())->method('update');
        $this->grid->expects(self::never())->method('refresh');
        $this->expectException(MaterialisationException::class);
        $this->persistence->finish($order, $draft, SourceOrderMetadata::fromDraft($draft));
    }
}
