<?php
/**
 * Copyright © Venuno. All rights reserved.
 */

declare(strict_types=1);

namespace Venuno\OrderImport\Test\Unit\Materialisation;

use PHPUnit\Framework\TestCase;
use Venuno\OrderImport\Model\Materialisation\ImportRowStoreInterface;
use Venuno\OrderImport\Model\Materialisation\MaterialisationException;
use Venuno\OrderImport\Model\Materialisation\NativeOrderGatewayInterface;
use Venuno\OrderImport\Model\Materialisation\OrderDraft;
use Venuno\OrderImport\Model\Materialisation\OrderDraftBuilder;
use Venuno\OrderImport\Model\Materialisation\OrderMaterialiser;
use Venuno\OrderImport\Model\Materialisation\TransactionRunnerInterface;

/**
 * @covers \Venuno\OrderImport\Model\Materialisation\OrderMaterialiser
 */
final class OrderMaterialiserTest extends TestCase
{
    private const KEY = 'magento:abc';

    public function testIdempotentNoOpWhenAlreadyMaterialised(): void
    {
        $store = $this->createMock(ImportRowStoreInterface::class);
        $store->method('find')->willReturn(['magento_order_id' => 555]);
        $store->expects(self::never())->method('claimForMaterialisation');
        $store->expects(self::never())->method('markImported');

        $gateway = $this->createMock(NativeOrderGatewayInterface::class);
        $gateway->expects(self::never())->method('place');

        $result = $this->materialiser($store, $gateway)->materialise(self::KEY);

        self::assertSame(555, $result->magentoOrderId);
        self::assertFalse($result->created, 'an existing order is an idempotent no-op (not billable)');
        self::assertSame('imported', $result->status);
    }

    public function testCreatesANativeOrderOnAFreshStagedRow(): void
    {
        $store = $this->createMock(ImportRowStoreInterface::class);
        $store->method('find')->willReturn(['magento_order_id' => 0]);
        $store->method('claimForMaterialisation')->willReturn(true);
        $store->expects(self::once())->method('markImported')->with(self::KEY, 42);
        $store->expects(self::never())->method('markFailed');

        $gateway = $this->createMock(NativeOrderGatewayInterface::class);
        $gateway->expects(self::once())->method('place')->willReturn(42);

        $result = $this->materialiser($store, $gateway)->materialise(self::KEY);

        self::assertSame(42, $result->magentoOrderId);
        self::assertTrue($result->created, 'a newly created order is billable');
        self::assertSame('imported', $result->status);
    }

    public function testMarkImportedRunsInsideTheTransaction(): void
    {
        $store = $this->createMock(ImportRowStoreInterface::class);
        $store->method('find')->willReturn(['magento_order_id' => 0]);
        $store->method('claimForMaterialisation')->willReturn(true);
        $gateway = $this->createMock(NativeOrderGatewayInterface::class);
        $gateway->method('place')->willReturn(7);

        $runner = new RecordingTransactionRunner();
        $marked = false;
        $store->method('markImported')->willReturnCallback(function () use ($runner, &$marked): void {
            // The order save + the ledger update must be inside the SAME transaction.
            self::assertTrue($runner->isInTransaction(), 'markImported must run inside the transaction');
            $marked = true;
        });

        $this->materialiser($store, $gateway, $runner)->materialise(self::KEY);

        self::assertTrue($marked);
        self::assertSame(1, $runner->runs);
    }

    public function testValidationFailureMarksFailedAndIsTerminalWithoutClaiming(): void
    {
        $store = $this->createMock(ImportRowStoreInterface::class);
        $store->method('find')->willReturn(['magento_order_id' => 0]);
        $store->expects(self::never())->method('claimForMaterialisation');
        $store->expects(self::once())->method('markFailed')->with(self::KEY, self::anything());

        $builder = $this->createMock(OrderDraftBuilder::class);
        $builder->method('fromImportRow')->willThrowException(
            new MaterialisationException('no items', MaterialisationException::REASON_NO_ITEMS, false)
        );

        $gateway = $this->createMock(NativeOrderGatewayInterface::class);
        $gateway->expects(self::never())->method('place');

        try {
            $this->materialiser($store, $gateway, null, $builder)->materialise(self::KEY);
            self::fail('expected MaterialisationException');
        } catch (MaterialisationException $e) {
            self::assertSame(MaterialisationException::REASON_NO_ITEMS, $e->getReason());
            self::assertFalse($e->isRetryable());
        }
    }

    public function testTransientGatewayFailureMarksFailedAndIsRetryable(): void
    {
        $store = $this->createMock(ImportRowStoreInterface::class);
        $store->method('find')->willReturn(['magento_order_id' => 0]);
        $store->method('claimForMaterialisation')->willReturn(true);
        $store->expects(self::once())->method('markFailed')->with(self::KEY, self::anything());
        $store->expects(self::never())->method('markImported');

        $gateway = $this->createMock(NativeOrderGatewayInterface::class);
        $gateway->method('place')->willThrowException(new \RuntimeException('database connection lost'));

        try {
            $this->materialiser($store, $gateway)->materialise(self::KEY);
            self::fail('expected MaterialisationException');
        } catch (MaterialisationException $e) {
            self::assertSame(MaterialisationException::REASON_ORDER_CREATE_FAILED, $e->getReason());
            self::assertTrue($e->isRetryable(), 'a transient save failure must be retryable');
        }
    }

    public function testTerminalGatewayFailureMarksFailedAndStaysTerminal(): void
    {
        $store = $this->createMock(ImportRowStoreInterface::class);
        $store->method('find')->willReturn(['magento_order_id' => 0]);
        $store->method('claimForMaterialisation')->willReturn(true);
        $store->expects(self::once())->method('markFailed');

        $gateway = $this->createMock(NativeOrderGatewayInterface::class);
        $gateway->method('place')->willThrowException(
            new MaterialisationException('SKU "X" not found', MaterialisationException::REASON_UNKNOWN_SKU, false)
        );

        try {
            $this->materialiser($store, $gateway)->materialise(self::KEY);
            self::fail('expected MaterialisationException');
        } catch (MaterialisationException $e) {
            self::assertSame(MaterialisationException::REASON_UNKNOWN_SKU, $e->getReason());
            self::assertFalse($e->isRetryable(), 'an unknown SKU is a terminal data problem');
        }
    }

    public function testLostClaimReturnsTheConcurrentlyCreatedOrder(): void
    {
        $store = $this->createMock(ImportRowStoreInterface::class);
        // First find: pending. After a lost claim, re-read shows the order another worker created.
        $store->method('find')->willReturnOnConsecutiveCalls(
            ['magento_order_id' => 0],
            ['magento_order_id' => 99]
        );
        $store->method('claimForMaterialisation')->willReturn(false);

        $gateway = $this->createMock(NativeOrderGatewayInterface::class);
        $gateway->expects(self::never())->method('place');

        $result = $this->materialiser($store, $gateway)->materialise(self::KEY);

        self::assertSame(99, $result->magentoOrderId);
        self::assertFalse($result->created);
        self::assertSame('imported', $result->status);
    }

    public function testLostClaimStillPendingReportsInProgress(): void
    {
        $store = $this->createMock(ImportRowStoreInterface::class);
        $store->method('find')->willReturnOnConsecutiveCalls(
            ['magento_order_id' => 0],
            ['magento_order_id' => 0]
        );
        $store->method('claimForMaterialisation')->willReturn(false);

        $gateway = $this->createMock(NativeOrderGatewayInterface::class);
        $gateway->expects(self::never())->method('place');

        $result = $this->materialiser($store, $gateway)->materialise(self::KEY);

        self::assertSame('in_progress', $result->status);
        self::assertFalse($result->created);
    }

    public function testNoStagedRowIsTerminal(): void
    {
        $store = $this->createMock(ImportRowStoreInterface::class);
        $store->method('find')->willReturn(null);

        $gateway = $this->createMock(NativeOrderGatewayInterface::class);
        $gateway->expects(self::never())->method('place');

        try {
            $this->materialiser($store, $gateway)->materialise(self::KEY);
            self::fail('expected MaterialisationException');
        } catch (MaterialisationException $e) {
            self::assertSame(MaterialisationException::REASON_NO_STAGED_ROW, $e->getReason());
            self::assertFalse($e->isRetryable());
        }
    }

    private function materialiser(
        ImportRowStoreInterface $store,
        NativeOrderGatewayInterface $gateway,
        ?TransactionRunnerInterface $runner = null,
        ?OrderDraftBuilder $builder = null
    ): OrderMaterialiser {
        if ($builder === null) {
            $builder = $this->createMock(OrderDraftBuilder::class);
            $builder->method('fromImportRow')->willReturn($this->draft());
        }
        return new OrderMaterialiser($store, $builder, $gateway, $runner ?? new RecordingTransactionRunner());
    }

    private function draft(): OrderDraft
    {
        return new OrderDraft(
            storeId: 4,
            extOrderId: '100000123',
            sourcePlatform: 'magento',
            sourceIncrementId: '100000123',
            sourceEntityId: '71951',
            currencyCode: 'GBP',
            customerEmail: 's@example.com',
            customerFirstname: 'Sandra',
            customerLastname: 'Smith',
            isVirtual: false,
            billingAddress: ['firstname' => 'Sandra'],
            shippingAddress: ['firstname' => 'Sandra'],
            shippingMethod: 'flatrate_flatrate',
            shippingDescription: 'Flat Rate',
            items: [['sku' => 'ABC', 'name' => 'Item', 'qty' => 2.0, 'price' => 10.0, 'row_total' => 20.0, 'tax_amount' => 4.0, 'discount_amount' => 0.0]],
            totals: ['subtotal' => 20.0, 'shipping' => 5.0, 'tax' => 4.0, 'discount' => 0.0, 'grand_total' => 29.0],
            paymentMethod: 'purchaseorder'
        );
    }
}

/**
 * A {@see TransactionRunnerInterface} that runs the work immediately (committing on success, surfacing
 * exceptions like a rollback), and records that the work executed within the transaction window.
 */
final class RecordingTransactionRunner implements TransactionRunnerInterface
{
    public int $runs = 0;
    private bool $inTransaction = false;

    public function run(callable $work): mixed
    {
        $this->runs++;
        $this->inTransaction = true;
        try {
            return $work();
        } finally {
            $this->inTransaction = false;
        }
    }

    public function isInTransaction(): bool
    {
        return $this->inTransaction;
    }
}
