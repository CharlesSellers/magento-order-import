<?php
declare(strict_types=1);

namespace Venuno\OrderImport\Test\Unit\Materialisation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Venuno\OrderImport\Model\Materialisation\MaterialisationException;
use Venuno\OrderImport\Model\Materialisation\OrderDraftBuilder;
use Venuno\OrderImport\Model\Materialisation\SourceOrderMetadata;

final class SourceOrderMetadataTest extends TestCase
{
    public static function row(array $header = [], array $identity = [], array $payload = []): array
    {
        return array_replace([
            'source_platform' => 'magento', 'source_store_id' => '4',
            'source_order_entity_id' => '123', 'source_order_increment_id' => '00000123',
            'original_created_at' => '2020-02-29 10:15:00',
            'request_payload' => json_encode(array_replace([
                'header' => array_replace([
                    'increment_id' => '00000123', 'store_id' => 4, 'order_currency_code' => 'GBP',
                    'created_at' => '2020-02-29 10:15:00', 'updated_at' => '2020-03-01 12:20:00',
                    'state' => 'processing', 'status' => 'processing',
                ], $header),
                'entity_id' => 123, 'increment_id' => '00000123', 'store_id' => 4,
                'billing_address' => ['email' => 'fixture@example.test', 'firstname' => 'Fixture'],
                'line_items' => [['sku' => 'FIXTURE', 'qty_ordered' => 1, 'price' => 10, 'row_total' => 10]],
                'totals' => ['subtotal' => 10, 'grand_total' => 10, 'order_currency_code' => 'GBP'],
            ], $payload), JSON_THROW_ON_ERROR),
        ], $identity);
    }

    public function testPreservesLeadingZeroNumberAndSourceDates(): void
    {
        $draft = (new OrderDraftBuilder())->fromImportRow(self::row());
        $metadata = SourceOrderMetadata::fromDraft($draft);
        self::assertSame('00000123', $metadata->incrementId);
        self::assertSame('2020-02-29 10:15:00', $metadata->createdAt);
        self::assertSame('2020-03-01 12:20:00', $metadata->updatedAt);
        self::assertSame('processing', $metadata->state);
        self::assertSame('processing', $metadata->status);
        self::assertSame(123, $draft->sourcePayloadIdentity['entity_id']);
    }

    public function testCustomStatusRemainsUnmodifiedForDestinationMappingCheck(): void
    {
        $draft = (new OrderDraftBuilder())->fromImportRow(self::row(['state' => 'holded', 'status' => 'awaiting_approval']));
        self::assertSame('awaiting_approval', SourceOrderMetadata::fromDraft($draft)->status);
    }

    public function testLegacyBuilderStillAcceptsPayloadWithoutMetadata(): void
    {
        $draft = (new OrderDraftBuilder())->fromImportRow(self::row([], [], ['header' => []]));
        self::assertSame([], $draft->sourceHeader);
        self::assertSame('GBP', $draft->currencyCode);
    }

    #[DataProvider('invalidMetadata')]
    public function testRejectsMissingOrConflictingMetadata(array $header, array $identity = [], array $payload = []): void
    {
        $draft = (new OrderDraftBuilder())->fromImportRow(self::row($header, $identity, $payload));
        try {
            SourceOrderMetadata::fromDraft($draft);
            self::fail('Expected a terminal metadata error');
        } catch (MaterialisationException $e) {
            self::assertSame(MaterialisationException::REASON_SOURCE_METADATA_INVALID, $e->getReason());
            self::assertFalse($e->isRetryable());
        }
    }

    public static function invalidMetadata(): iterable
    {
        yield 'missing number' => [['increment_id' => null]];
        yield 'number must remain a string' => [['increment_id' => 123]];
        yield 'number mismatch' => [['increment_id' => '123']];
        yield 'oversized number' => [['increment_id' => str_repeat('1', 51)]];
        yield 'control in number' => [['increment_id' => "00000123\n"]];
        yield 'missing created' => [['created_at' => null]];
        yield 'invalid leap date' => [['created_at' => '2021-02-29 10:15:00']];
        yield 'timezone must not be guessed' => [['created_at' => '2020-02-29T10:15:00Z']];
        yield 'missing updated' => [['updated_at' => '']];
        yield 'updated before created' => [['updated_at' => '2020-02-28 12:20:00']];
        yield 'outer creation conflict' => [[], ['original_created_at' => '2020-02-28 10:15:00']];
        yield 'missing state' => [['state' => null]];
        yield 'invalid state' => [['state' => 'made_up']];
        yield 'missing status' => [['status' => null]];
        yield 'long status' => [['status' => str_repeat('s', 33)]];
        yield 'missing currency' => [['order_currency_code' => null]];
        yield 'lower case currency' => [['order_currency_code' => 'gbp']];
        yield 'currency conflict' => [['order_currency_code' => 'USD']];
        yield 'wrong source' => [[], ['source_platform' => 'shopify']];
        yield 'source id absent' => [[], ['source_order_entity_id' => '']];
        yield 'source id conflict' => [[], [], ['entity_id' => 124]];
        yield 'payload number conflict' => [[], [], ['increment_id' => '123']];
        yield 'payload store conflict' => [[], [], ['store_id' => 5]];
        yield 'header store conflict' => [['store_id' => 5]];
        yield 'structured identity rejected' => [[], [], ['entity_id' => ['123']]];
    }
}
