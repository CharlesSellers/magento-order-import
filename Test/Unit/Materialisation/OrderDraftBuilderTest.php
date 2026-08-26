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
        $this->assertReason(MaterialisationException::REASON_BAD_PAYLOAD, fn () => $this->builder->fromImportRow($this->row([], ['request_payload' => ''])));
    }

    public function testRejectsInvalidJson(): void
    {
        $this->assertReason(MaterialisationException::REASON_BAD_PAYLOAD, fn () => $this->builder->fromImportRow($this->row([], ['request_payload' => 'not json'])));
    }

    public function testRejectsAnOrderWithNoLineItems(): void
    {
        $this->assertReason(MaterialisationException::REASON_NO_ITEMS, fn () => $this->builder->fromImportRow($this->row(['line_items' => []])));
    }

    public function testRejectsALineItemWithNoSku(): void
    {
        $this->assertReason(MaterialisationException::REASON_MISSING_FIELD, fn () => $this->builder->fromImportRow($this->row(['line_items' => [['name' => 'No SKU', 'qty_ordered' => 1]]])));
    }

    public function testRejectsANonPositiveQuantity(): void
    {
        $this->assertReason(MaterialisationException::REASON_MISSING_FIELD, fn () => $this->builder->fromImportRow($this->row(['line_items' => [['sku' => 'ABC', 'qty_ordered' => 0]]])));
    }

    public function testRejectsAMissingCustomerEmail(): void
    {
        $this->assertReason(MaterialisationException::REASON_MISSING_FIELD, fn () => $this->builder->fromImportRow($this->row(['billing_address' => ['firstname' => 'X'], 'shipping_address' => []])));
    }

    public function testRejectsAMissingStoreId(): void
    {
        $this->assertReason(MaterialisationException::REASON_MISSING_FIELD, fn () => $this->builder->fromImportRow($this->row(['store_id' => ''], ['source_store_id' => ''])));
    }

    // --- 0.4 customer linking: parsing the sibling `customer` block ---

    public function testLegacyPayloadWithoutCustomerBlockStaysGuest(): void
    {
        // No `customer` block => pre-0.4 payload => explicit guest (the 0.3 behaviour), the documented fallback.
        $draft = $this->builder->fromImportRow($this->row());
        self::assertTrue($draft->sourceCustomerIsGuest);
        self::assertFalse($draft->customerRegistrationRequired);
        self::assertNull($draft->customerAccountEmail);
        self::assertNull($draft->sourceCustomerId);
        self::assertNull($draft->sourceGroupId);
    }

    public function testExplicitGuestCustomerBlockIsGuest(): void
    {
        $draft = $this->builder->fromImportRow($this->row([
            'customer' => [
                'source_customer_is_guest' => true,
                'registration_required' => false,
                'email' => 'ignored@example.com',
            ],
        ]));
        self::assertTrue($draft->sourceCustomerIsGuest);
        self::assertFalse($draft->customerRegistrationRequired);
        self::assertSame('ignored@example.com', $draft->customerAccountEmail);
    }

    public function testCurrentCustomerBlockCarriesResolutionIdentityAccountReferenceAndProvenance(): void
    {
        $draft = $this->builder->fromImportRow($this->row([
            'customer' => [
                'email' => 'account@example.com',
                'firstname' => 'Account',
                'lastname' => 'Buyer',
                'source_customer_id' => '888',
                'source_customer_group_id' => '5',
                'source_customer_is_guest' => false,
                'registration_required' => true,
                'account_reference' => 'BC061-5',
                'account_reference_attribute' => 'short_account_ref',
            ],
        ]));
        self::assertFalse($draft->sourceCustomerIsGuest);
        self::assertTrue($draft->customerRegistrationRequired);
        self::assertSame('account@example.com', $draft->customerAccountEmail);
        self::assertSame('BC061-5', $draft->accountReference);
        self::assertSame('short_account_ref', $draft->accountReferenceAttribute);
        self::assertSame('account@example.com', $draft->customerEmail);
        self::assertSame('Account', $draft->customerFirstname);
        self::assertSame('Buyer', $draft->customerLastname);
        // Provenance is recorded but is NOT used for assignment (the gateway resolves the destination customer).
        self::assertSame('888', $draft->sourceCustomerId);
        self::assertSame('5', $draft->sourceGroupId);
    }

    public function testRegistrationRequiredSourceGuestStillCarriesResolutionEmail(): void
    {
        $draft = $this->builder->fromImportRow($this->row([
            'customer' => [
                'email' => 'registered@example.com',
                'source_customer_is_guest' => true,
                'registration_required' => true,
            ],
        ]));

        self::assertTrue($draft->sourceCustomerIsGuest);
        self::assertTrue($draft->customerRegistrationRequired);
        self::assertSame('registered@example.com', $draft->customerAccountEmail);
    }

    public function testRejectsRegistrationRequiredCustomerBlockWithoutEmail(): void
    {
        $this->assertReason(MaterialisationException::REASON_MISSING_FIELD, fn () => $this->builder->fromImportRow($this->row(['customer' => [
                'source_customer_is_guest' => true,
                'registration_required' => true,
            ]])));
    }

    public function testRejectsCustomerBlockWithoutSourceGuestFlag(): void
    {
        $this->assertReason(MaterialisationException::REASON_MISSING_FIELD, fn () => $this->builder->fromImportRow($this->row(['customer' => [
                'registration_required' => true,
                'email' => 'x@example.com',
            ]])));
    }

    public function testRejectsCustomerBlockWithNonBooleanFlags(): void
    {
        $this->assertReason(MaterialisationException::REASON_MISSING_FIELD, fn () => $this->builder->fromImportRow($this->row(['customer' => [
                'source_customer_is_guest' => 'maybe',
                'registration_required' => true,
                'email' => 'x@example.com',
            ]])));

        $this->assertReason(MaterialisationException::REASON_MISSING_FIELD, fn () => $this->builder->fromImportRow($this->row(['customer' => [
                'source_customer_is_guest' => false,
                'registration_required' => 'maybe',
                'email' => 'x@example.com',
            ]])));
    }

    public function testRejectsInvalidAccountReferenceAttributeCode(): void
    {
        $this->assertReason(MaterialisationException::REASON_BAD_PAYLOAD, fn () => $this->builder->fromImportRow($this->row(['customer' => [
                'source_customer_is_guest' => false,
                'registration_required' => true,
                'email' => 'x@example.com',
                'account_reference_attribute' => 'bad field!',
            ]])));
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
