<?php
/**
 * Copyright © Venuno. All rights reserved.
 */

declare(strict_types=1);

namespace Venuno\OrderImport\Test\Unit\Materialisation;

use PHPUnit\Framework\TestCase;
use Venuno\OrderImport\Model\Materialisation\MaterialisationException;
use Venuno\OrderImport\Model\Materialisation\OrderDraftBuilder;

/**
 * @covers \Venuno\OrderImport\Model\Materialisation\OrderDraftBuilder
 */
final class OrderDraftBuilderTest extends TestCase
{
    private OrderDraftBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new OrderDraftBuilder();
    }

    public function testMapsAValidRowToADraft(): void
    {
        $draft = $this->builder->fromImportRow($this->row());

        self::assertSame(4, $draft->storeId);
        self::assertSame('100000123', $draft->extOrderId);
        self::assertSame('magento', $draft->sourcePlatform);
        self::assertSame('71951', $draft->sourceEntityId);
        self::assertSame('GBP', $draft->currencyCode);
        self::assertSame('s@example.com', $draft->customerEmail);
        self::assertSame('Sandra', $draft->customerFirstname);
        self::assertSame('Smith', $draft->customerLastname);
        self::assertFalse($draft->isVirtual);
        self::assertNotNull($draft->shippingAddress);
        self::assertSame('flatrate_flatrate', $draft->shippingMethod);
        self::assertSame('purchaseorder', $draft->paymentMethod);

        self::assertCount(1, $draft->items);
        self::assertSame('ABC', $draft->items[0]['sku']);
        self::assertSame(2.0, $draft->items[0]['qty']);
        self::assertSame(20.0, $draft->items[0]['row_total']);

        self::assertSame(20.0, $draft->totals['subtotal']);
        self::assertSame(5.0, $draft->totals['shipping']);
        self::assertSame(4.0, $draft->totals['tax']);
        self::assertSame(29.0, $draft->totals['grand_total']);
    }

    public function testDefaultsPaymentToCheckmoWhenAbsent(): void
    {
        $draft = $this->builder->fromImportRow($this->row(['payment' => []]));
        self::assertSame('checkmo', $draft->paymentMethod);
    }

    public function testVirtualOrderHasNoShippingAddress(): void
    {
        $draft = $this->builder->fromImportRow($this->row(['header' => $this->header(['is_virtual' => 1])]));
        self::assertTrue($draft->isVirtual);
        self::assertNull($draft->shippingAddress);
    }

    public function testFallsBackToBillingWhenShippingAddressMissing(): void
    {
        $draft = $this->builder->fromImportRow($this->row(['shipping_address' => []]));
        self::assertNotNull($draft->shippingAddress);
        self::assertSame('Sandra', $draft->shippingAddress['firstname']);
    }

    public function testExtOrderIdFallsBackToEntityIdWhenIncrementMissing(): void
    {
        $draft = $this->builder->fromImportRow(
            $this->row([], ['source_order_increment_id' => '', 'source_order_display_number' => ''])
        );
        self::assertSame('71951', $draft->extOrderId);
    }

    public function testRejectsAnEmptyPayload(): void
    {
        $this->assertReason(MaterialisationException::REASON_BAD_PAYLOAD, fn () =>
            $this->builder->fromImportRow($this->row([], ['request_payload' => ''])));
    }

    public function testRejectsInvalidJson(): void
    {
        $this->assertReason(MaterialisationException::REASON_BAD_PAYLOAD, fn () =>
            $this->builder->fromImportRow($this->row([], ['request_payload' => 'not json'])));
    }

    public function testRejectsAnOrderWithNoLineItems(): void
    {
        $this->assertReason(MaterialisationException::REASON_NO_ITEMS, fn () =>
            $this->builder->fromImportRow($this->row(['line_items' => []])));
    }

    public function testRejectsALineItemWithNoSku(): void
    {
        $this->assertReason(MaterialisationException::REASON_MISSING_FIELD, fn () =>
            $this->builder->fromImportRow($this->row(['line_items' => [['name' => 'No SKU', 'qty_ordered' => 1]]])));
    }

    public function testRejectsANonPositiveQuantity(): void
    {
        $this->assertReason(MaterialisationException::REASON_MISSING_FIELD, fn () =>
            $this->builder->fromImportRow($this->row(['line_items' => [['sku' => 'ABC', 'qty_ordered' => 0]]])));
    }

    public function testRejectsAMissingCustomerEmail(): void
    {
        $this->assertReason(MaterialisationException::REASON_MISSING_FIELD, fn () =>
            $this->builder->fromImportRow($this->row(['billing_address' => ['firstname' => 'X'], 'shipping_address' => []])));
    }

    public function testRejectsAMissingStoreId(): void
    {
        $this->assertReason(MaterialisationException::REASON_MISSING_FIELD, fn () =>
            $this->builder->fromImportRow($this->row(['store_id' => ''], ['source_store_id' => ''])));
    }

    /** Every builder failure is a terminal data problem — never retryable. */
    private function assertReason(string $reason, callable $fn): void
    {
        try {
            $fn();
            self::fail('expected MaterialisationException with reason ' . $reason);
        } catch (MaterialisationException $e) {
            self::assertSame($reason, $e->getReason());
            self::assertFalse($e->isRetryable(), 'a data/mapping failure must be terminal');
        }
    }

    /**
     * @param array<string, mixed> $orderOverrides override keys in the decoded `order` payload
     * @param array<string, mixed> $rowOverrides   override keys on the venuno_order_import row
     * @return array<string, mixed>
     */
    private function row(array $orderOverrides = [], array $rowOverrides = []): array
    {
        $order = array_merge([
            'header' => $this->header(),
            'billing_address' => [
                'firstname' => 'Sandra', 'lastname' => 'Smith', 'street' => ['1 High St'],
                'city' => 'London', 'postcode' => 'E1', 'country_id' => 'GB', 'telephone' => '01',
                'email' => 's@example.com',
            ],
            'shipping_address' => [
                'firstname' => 'Sandra', 'lastname' => 'Smith', 'street' => ['1 High St'],
                'city' => 'London', 'postcode' => 'E1', 'country_id' => 'GB',
            ],
            'shipping_method' => 'flatrate_flatrate',
            'shipping_description' => 'Flat Rate - Fixed',
            'line_items' => [[
                'sku' => 'ABC', 'name' => 'Item', 'qty_ordered' => 2, 'price' => 10.0,
                'row_total' => 20.0, 'tax_amount' => 4.0, 'discount_amount' => 0.0,
            ]],
            'totals' => [
                'subtotal' => 20.0, 'shipping_amount' => 5.0, 'discount_amount' => 0.0,
                'tax_amount' => 4.0, 'grand_total' => 29.0, 'order_currency_code' => 'GBP',
            ],
            'payment' => ['method' => 'purchaseorder'],
            'store_id' => 4,
        ], $orderOverrides);

        return array_merge([
            'source_platform' => 'magento',
            'source_base_url' => 'https://www.jangro.net',
            'source_store_id' => '4',
            'source_order_entity_id' => '71951',
            'source_order_increment_id' => '100000123',
            'source_order_display_number' => '100000123',
            'magento_order_id' => 0,
            'import_status' => 'pending',
            'request_payload' => json_encode($order),
        ], $rowOverrides);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function header(array $overrides = []): array
    {
        return array_merge([
            'increment_id' => '100000123',
            'order_currency_code' => 'GBP',
            'is_virtual' => 0,
            'store_id' => 4,
        ], $overrides);
    }
}
