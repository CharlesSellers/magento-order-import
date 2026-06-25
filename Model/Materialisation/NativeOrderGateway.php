<?php
/**
 * Copyright © Venuno. All rights reserved.
 */

declare(strict_types=1);

namespace Venuno\OrderImport\Model\Materialisation;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Customer\Model\Group;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\AddressFactory as OrderAddressFactory;
use Magento\Sales\Model\Order\ItemFactory as OrderItemFactory;
use Magento\Sales\Model\Order\PaymentFactory as OrderPaymentFactory;
use Magento\Sales\Model\OrderFactory;

/**
 * Creates a native Magento sales order from an {@see OrderDraft} by **direct construction** (no quote),
 * so the source order's amounts are reproduced verbatim — replication preserves A's totals rather than
 * recomputing them from B's catalogue/tax/shipping rules. Building without a quote also means
 * materialisation does **not** touch B's inventory, matching the order-only replication scope.
 *
 * The whole save is performed by the caller inside a transaction ({@see OrderMaterialiser}), so a failure
 * here rolls back atomically. The only failure this gateway classifies itself is an unknown SKU
 * (terminal); transient save failures propagate and are surfaced as retryable by the orchestrator.
 *
 * Magento order creation can only be trusted after validation against a live Magento (module ADR-0003),
 * so this class is exercised by integration tests; the surrounding logic is unit-tested via the
 * {@see NativeOrderGatewayInterface} seam.
 */
class NativeOrderGateway implements NativeOrderGatewayInterface
{
    public function __construct(
        private readonly OrderFactory $orderFactory,
        private readonly OrderItemFactory $orderItemFactory,
        private readonly OrderAddressFactory $orderAddressFactory,
        private readonly OrderPaymentFactory $orderPaymentFactory,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly OrderRepositoryInterface $orderRepository
    ) {
    }

    public function place(OrderDraft $draft): int
    {
        $order = $this->orderFactory->create();
        $order->setStoreId($draft->storeId);
        $order->setState(Order::STATE_NEW);
        $order->setStatus('pending');

        // Preserve the external reference: the source order number is recorded as the order's
        // ext_order_id (and again in a status-history comment below) so B↔A is always traceable.
        $order->setExtOrderId($draft->extOrderId);

        $order->setCustomerIsGuest(true);
        $order->setCustomerGroupId(Group::NOT_LOGGED_IN_ID);
        $order->setCustomerEmail($draft->customerEmail);
        $order->setCustomerFirstname($draft->customerFirstname);
        $order->setCustomerLastname($draft->customerLastname);

        $currency = $draft->currencyCode !== '' ? $draft->currencyCode : 'USD';
        $order->setOrderCurrencyCode($currency);
        $order->setBaseCurrencyCode($currency);
        $order->setStoreCurrencyCode($currency);
        $order->setGlobalCurrencyCode($currency);

        $order->setBillingAddress($this->buildAddress($draft->billingAddress, 'billing'));
        if (!$draft->isVirtual && $draft->shippingAddress !== null) {
            $order->setShippingAddress($this->buildAddress($draft->shippingAddress, 'shipping'));
            $order->setShippingMethod($draft->shippingMethod);
            $order->setShippingDescription($draft->shippingDescription);
        } else {
            $order->setIsVirtual(true);
        }

        $totalQty = 0.0;
        foreach ($draft->items as $item) {
            $order->addItem($this->buildItem($item, $draft->storeId));
            $totalQty += (float) $item['qty'];
        }

        $this->applyTotals($order, $draft, $totalQty, count($draft->items));

        $payment = $this->orderPaymentFactory->create();
        $payment->setMethod($draft->paymentMethod);
        $order->setPayment($payment);

        $order->addCommentToStatusHistory(
            sprintf(
                'Imported from %s order %s (entity_id %s) via Venuno.',
                $draft->sourcePlatform !== '' ? $draft->sourcePlatform : 'source',
                $draft->sourceIncrementId !== '' ? $draft->sourceIncrementId : '(unknown)',
                $draft->sourceEntityId !== '' ? $draft->sourceEntityId : '(unknown)'
            )
        );

        $saved = $this->orderRepository->save($order);

        return (int) $saved->getEntityId();
    }

    /**
     * @param array<string, mixed> $item
     * @throws MaterialisationException when the SKU does not exist in the destination store (terminal)
     */
    private function buildItem(array $item, int $storeId): Order\Item
    {
        $sku = (string) $item['sku'];
        try {
            $product = $this->productRepository->get($sku, false, $storeId);
        } catch (NoSuchEntityException $e) {
            throw new MaterialisationException(
                sprintf('SKU "%s" does not exist in the destination store.', $sku),
                MaterialisationException::REASON_UNKNOWN_SKU,
                false,
                $e
            );
        }

        $price = (float) $item['price'];
        $rowTotal = (float) $item['row_total'];

        $orderItem = $this->orderItemFactory->create();
        $orderItem->setStoreId($storeId);
        $orderItem->setProductId((int) $product->getId());
        $orderItem->setProductType((string) $product->getTypeId());
        $orderItem->setSku($sku);
        $orderItem->setName($item['name'] !== null ? (string) $item['name'] : (string) $product->getName());
        $orderItem->setQtyOrdered((float) $item['qty']);
        $orderItem->setPrice($price);
        $orderItem->setBasePrice($price);
        $orderItem->setOriginalPrice($price);
        $orderItem->setBaseOriginalPrice($price);
        $orderItem->setRowTotal($rowTotal);
        $orderItem->setBaseRowTotal($rowTotal);
        $orderItem->setTaxAmount((float) $item['tax_amount']);
        $orderItem->setBaseTaxAmount((float) $item['tax_amount']);
        $orderItem->setDiscountAmount((float) $item['discount_amount']);
        $orderItem->setBaseDiscountAmount((float) $item['discount_amount']);

        return $orderItem;
    }

    /**
     * @param array<string, mixed> $address
     */
    private function buildAddress(array $address, string $type): Order\Address
    {
        $orderAddress = $this->orderAddressFactory->create();
        $orderAddress->setAddressType($type);
        $orderAddress->setFirstname((string) ($address['firstname'] ?? ''));
        $orderAddress->setLastname((string) ($address['lastname'] ?? ''));
        if (!empty($address['company'])) {
            $orderAddress->setCompany((string) $address['company']);
        }

        $street = $address['street'] ?? [];
        $orderAddress->setStreet(is_array($street) ? array_map('strval', $street) : [(string) $street]);

        $orderAddress->setCity((string) ($address['city'] ?? ''));
        if (isset($address['region']) && $address['region'] !== null && $address['region'] !== '') {
            $orderAddress->setRegion((string) $address['region']);
        }
        if (isset($address['region_id']) && is_numeric($address['region_id'])) {
            $orderAddress->setRegionId((int) $address['region_id']);
        }
        $orderAddress->setPostcode((string) ($address['postcode'] ?? ''));
        $orderAddress->setCountryId((string) ($address['country_id'] ?? ''));
        $orderAddress->setTelephone((string) ($address['telephone'] ?? ''));
        if (!empty($address['email'])) {
            $orderAddress->setEmail((string) $address['email']);
        }

        return $orderAddress;
    }

    private function applyTotals(Order $order, OrderDraft $draft, float $totalQty, int $itemCount): void
    {
        $t = $draft->totals;
        $order->setSubtotal((float) $t['subtotal']);
        $order->setBaseSubtotal((float) $t['subtotal']);
        $order->setGrandTotal((float) $t['grand_total']);
        $order->setBaseGrandTotal((float) $t['grand_total']);
        $order->setShippingAmount((float) $t['shipping']);
        $order->setBaseShippingAmount((float) $t['shipping']);
        $order->setTaxAmount((float) $t['tax']);
        $order->setBaseTaxAmount((float) $t['tax']);
        $order->setDiscountAmount((float) $t['discount']);
        $order->setBaseDiscountAmount((float) $t['discount']);
        $order->setTotalQtyOrdered($totalQty);
        $order->setTotalItemCount($itemCount);
    }
}
