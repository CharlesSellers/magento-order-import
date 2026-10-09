<?php
declare(strict_types=1);

namespace Venuno\OrderImport\Test\Unit\Materialisation;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Sales\Model\ResourceModel\Grid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Venuno\OrderImport\Model\Materialisation\MaterialisationException;
use Venuno\OrderImport\Model\Materialisation\OrderDraftBuilder;
use Venuno\OrderImport\Model\Materialisation\OrderHistory;
use Venuno\OrderImport\Model\Materialisation\SourceMetadataPersistence;
use Venuno\OrderImport\Model\Materialisation\SourceOrderMetadata;
use Venuno\OrderImport\Test\Fixtures\OrderHistoryFixture;

/**
 * Regression: Jangro legacy orders 1000087031 (entity 235775) and 1000087095 (entity 235877) failed with
 * "Source order state is not a supported Magento state." because the legacy amasty_stripe payment method
 * stored state `pending` / status `pending`. Status `pending` is assigned only to state `new` on both
 * legacy and destination, so exactly that pair is stored as new/pending; everything else is unchanged.
 */
final class LegacyPendingStateTest extends TestCase
{
    /** Header values copied from the stored ledger payloads (ledger rows 197502 / 197608). */
    public static function failingJangroOrders(): iterable
    {
        yield '1000087031 (ledger 197502)' => ['235775', '1000087031', '2026-10-02 10:20:49', '2026-10-02 10:20:50'];
        yield '1000087095 (ledger 197608)' => ['235877', '1000087095', '2026-10-04 07:59:38', '2026-10-04 07:59:39'];
    }

    private static function jangroRow(string $entityId, string $number, string $created, string $updated): array
    {
        return SourceOrderMetadataTest::row(
            ['increment_id' => $number, 'store_id' => 1, 'created_at' => $created, 'updated_at' => $updated,
                'state' => 'pending', 'status' => 'pending'],
            ['source_store_id' => '1', 'source_order_entity_id' => $entityId, 'source_order_increment_id' => $number,
                'original_created_at' => $created],
            ['entity_id' => (int) $entityId, 'increment_id' => $number, 'store_id' => 1]
        );
    }

    #[DataProvider('failingJangroOrders')]
    public function testFailingJangroOrderMapsToNewAndKeepsPendingStatus(string $entityId, string $number, string $created, string $updated): void
    {
        $draft = (new OrderDraftBuilder())->fromImportRow(self::jangroRow($entityId, $number, $created, $updated));
        $metadata = SourceOrderMetadata::fromDraft($draft);
        self::assertSame('new', $metadata->state);
        self::assertSame('pending', $metadata->status);
        self::assertSame('pending', $metadata->sourceState);
        self::assertTrue($metadata->stateWasNormalised());
        self::assertSame($number, $metadata->incrementId, 'order number must be preserved');
        self::assertSame($created, $metadata->createdAt);
        self::assertSame($updated, $metadata->updatedAt);
        self::assertSame($entityId, $draft->sourceEntityId);
        self::assertSame('pending', $draft->sourceHeader['state'], 'the stored source header is not rewritten');
    }

    /** Every pair already accepted before the fix keeps its exact state and status. */
    public static function unchangedPairs(): iterable
    {
        foreach ([
            ['new', 'pending'], ['new', 'awaiting_approval'], ['new', 'approved'], ['new', 'rejected'],
            ['pending_payment', 'pending_payment'], ['processing', 'processing'], ['processing', 'complete'],
            ['processing', 'processed_ogone'], ['complete', 'complete'], ['complete', 'approved'],
            ['closed', 'closed'], ['closed', 'approved'], ['canceled', 'canceled'], ['holded', 'holded'],
            ['payment_review', 'payment_review'],
        ] as [$state, $status]) {
            yield "$state/$status" => [$state, $status];
        }
    }

    #[DataProvider('unchangedPairs')]
    public function testOtherStatesAreUnchanged(string $state, string $status): void
    {
        $metadata = SourceOrderMetadata::fromDraft((new OrderDraftBuilder())->fromImportRow(
            SourceOrderMetadataTest::row(['state' => $state, 'status' => $status])
        ));
        self::assertSame($state, $metadata->state);
        self::assertSame($status, $metadata->status);
        self::assertSame($state, $metadata->sourceState);
        self::assertFalse($metadata->stateWasNormalised());
    }

    /** Still terminal: only the exact pending/pending pair is normalised. */
    public static function stillRejected(): iterable
    {
        yield 'pending with processing status' => ['pending', 'processing'];
        yield 'pending with pending_payment status' => ['pending', 'pending_payment'];
        yield 'pending with approved status' => ['pending', 'approved'];
        yield 'pending with awaiting_approval status' => ['pending', 'awaiting_approval'];
        yield 'case variant state' => ['Pending', 'pending'];
        yield 'case variant status' => ['pending', 'Pending'];
        yield 'unknown state' => ['made_up', 'pending'];
        yield 'extension state' => ['stripe_disputed', 'stripe_disputed'];
        yield 'pending_paypal state' => ['pending_paypal', 'pending_paypal'];
    }

    #[DataProvider('stillRejected')]
    public function testUnsupportedStatesAreStillRejected(string $state, string $status): void
    {
        $draft = (new OrderDraftBuilder())->fromImportRow(SourceOrderMetadataTest::row(['state' => $state, 'status' => $status]));
        try {
            SourceOrderMetadata::fromDraft($draft);
            self::fail('Expected a terminal metadata error');
        } catch (MaterialisationException $e) {
            self::assertSame('Source order state is not a supported Magento state.', $e->getMessage());
            self::assertSame(MaterialisationException::REASON_SOURCE_METADATA_INVALID, $e->getReason());
            self::assertFalse($e->isRetryable());
        }
    }

    /** Complete-history (production) mode: invoiced + paid, unshipped amasty_stripe-style order. */
    private static function pendingHistoryRow(): array
    {
        $row = OrderHistoryFixture::row(1, 0, 0);
        $payload = json_decode($row['request_payload'], true, 512, JSON_THROW_ON_ERROR);
        $payload['header']['state'] = $payload['header']['status'] = 'pending';
        $payload['history']['order']['state'] = $payload['history']['order']['status'] = 'pending';
        foreach ($payload['history']['status_histories'] as &$h) {
            $h['status'] = 'pending';
        }
        $row['request_payload'] = json_encode($payload, JSON_THROW_ON_ERROR);
        return $row;
    }

    public function testCompleteHistoryStillValidatesAndKeepsInvoiceAndPaymentHistory(): void
    {
        $draft = (new OrderDraftBuilder())->fromImportRow(self::pendingHistoryRow());
        $history = OrderHistory::fromDraft($draft);
        $metadata = SourceOrderMetadata::fromDraft($draft);
        self::assertSame('pending', $history->data['order']['state'], 'archived legacy header is kept verbatim');
        self::assertSame(['new', 'pending'], [$metadata->state, $metadata->status]);
        self::assertCount(1, $history->data['invoices']);
        self::assertCount(0, $history->data['shipments']);
        self::assertEquals(10, $history->data['order']['total_paid']);
        self::assertEquals(10, $history->data['payment']['amount_paid']);
    }

    public function testDestinationMappingIsCheckedForNewPendingAndNothingIsWritten(): void
    {
        $wheres = [];
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('limit')->willReturnSelf();
        $select->method('where')->willReturnCallback(function (string $cond, $value = null) use (&$wheres, $select) {
            $wheres[] = [$cond, $value];
            return $select;
        });
        $db = $this->createMock(AdapterInterface::class);
        $db->method('select')->willReturn($select);
        $db->method('getTransactionLevel')->willReturn(1);
        // 1) sales_order_status_state(new, pending) is mapped; 2) no order already holds the number.
        $db->expects(self::exactly(2))->method('fetchOne')->willReturnOnConsecutiveCalls('pending', false);
        $db->expects(self::never())->method('insert');
        $db->expects(self::never())->method('update');
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($db);
        $resource->method('getTableName')->willReturnCallback(static fn ($name) => $name);

        $draft = (new OrderDraftBuilder())->fromImportRow(self::pendingHistoryRow());
        (new SourceMetadataPersistence($resource, $this->createMock(Grid::class)))
            ->assertAvailable($draft, SourceOrderMetadata::fromDraft($draft), OrderHistory::fromDraft($draft));

        self::assertContains(['state = ?', 'new'], $wheres);
        self::assertContains(['status = ?', 'pending'], $wheres);
        self::assertNotContains(['state = ?', 'pending'], $wheres);
    }
}
