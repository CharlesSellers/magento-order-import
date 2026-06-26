<?php
/**
 * Copyright © Venuno. All rights reserved.
 */

declare(strict_types=1);

namespace Venuno\OrderImport\Model\Materialisation;

/**
 * Immutable, Magento-free description of the native order to create. Produced by {@see OrderDraftBuilder}
 * from a staged `venuno_order_import` row and consumed by {@see NativeOrderGatewayInterface}. Keeping it
 * a plain value object is what lets the materialisation logic be unit-tested without a Magento runtime.
 *
 * Amounts are the **source** order's amounts — replication reproduces A's order faithfully rather than
 * recomputing totals from B's catalogue/tax/shipping configuration.
 *
 * @phpstan-type AddressArray array<string, mixed>
 * @phpstan-type ItemArray array{sku:string,name:?string,qty:float,price:float,row_total:float,tax_amount:float,discount_amount:float}
 */
class OrderDraft
{
    /**
     * @param array<string, mixed> $billingAddress
     * @param array<string, mixed>|null $shippingAddress
     * @param array<int, array<string, mixed>> $items
     * @param array{subtotal:float,shipping:float,tax:float,discount:float,grand_total:float} $totals
     */
    public function __construct(
        public readonly int $storeId,
        public readonly string $extOrderId,
        public readonly string $sourcePlatform,
        public readonly string $sourceIncrementId,
        public readonly string $sourceEntityId,
        public readonly string $currencyCode,
        public readonly string $customerEmail,
        public readonly ?string $customerFirstname,
        public readonly ?string $customerLastname,
        public readonly bool $isVirtual,
        public readonly array $billingAddress,
        public readonly ?array $shippingAddress,
        public readonly ?string $shippingMethod,
        public readonly ?string $shippingDescription,
        public readonly array $items,
        public readonly array $totals,
        public readonly string $paymentMethod
    ) {
    }
}
