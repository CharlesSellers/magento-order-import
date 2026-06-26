<?php
/**
 * Copyright © Venuno. All rights reserved.
 */

declare(strict_types=1);

namespace Venuno\OrderImport\Model\Materialisation;

/**
 * Builds an {@see OrderDraft} from a staged `venuno_order_import` row. Pure (no Magento dependency) and
 * the single place that interprets the stored payload, so the mapping + validation are unit-testable.
 *
 * Identity (destination store, external reference) comes from the row's `source_*` columns — the
 * authoritative composite identity (ADR-0002), with the *destination* store resolved by the exact
 * `store_id` mapping (GOLDEN_CUSTOMER_02). Order content (addresses, line items, totals, payment) comes
 * from the row's `request_payload` (the `order` JSON the client carried opaquely).
 *
 * Every failure here is a **terminal** data problem ({@see MaterialisationException} retryable=false):
 * the staged payload cannot become a valid order until the data (or the destination catalogue) is fixed.
 */
class OrderDraftBuilder
{
    /**
     * @param array<string, mixed> $row a venuno_order_import row
     * @throws MaterialisationException
     */
    public function fromImportRow(array $row): OrderDraft
    {
        $order = $this->decodePayload((string) ($row['request_payload'] ?? ''));

        $storeId = $this->resolveStoreId($row, $order);
        $extOrderId = $this->firstNonEmpty(
            (string) ($row['source_order_increment_id'] ?? ''),
            (string) ($row['source_order_display_number'] ?? ''),
            (string) ($row['source_order_entity_id'] ?? '')
        );

        $billing = $this->arrayField($order, 'billing_address');
        $shipping = $this->arrayField($order, 'shipping_address');
        $header = $this->arrayField($order, 'header');
        $isVirtual = $this->toBool($header['is_virtual'] ?? false);

        $email = $this->firstNonEmpty(
            (string) ($billing['email'] ?? ''),
            (string) ($shipping['email'] ?? '')
        );
        if ($email === '') {
            throw new MaterialisationException(
                'Order has no customer email (required to create a Magento order).',
                MaterialisationException::REASON_MISSING_FIELD,
                false
            );
        }
        if ($billing === []) {
            throw new MaterialisationException(
                'Order has no billing address.',
                MaterialisationException::REASON_MISSING_FIELD,
                false
            );
        }

        $items = $this->buildItems($order);
        $totals = $this->buildTotals($order);
        $currency = $this->firstNonEmpty(
            (string) ($totals['currency'] ?? ''),
            (string) ($header['order_currency_code'] ?? ''),
            (string) ($order['order_currency_code'] ?? '')
        );

        return new OrderDraft(
            storeId: $storeId,
            extOrderId: $extOrderId,
            sourcePlatform: (string) ($row['source_platform'] ?? 'magento'),
            sourceIncrementId: (string) ($row['source_order_increment_id'] ?? ''),
            sourceEntityId: (string) ($row['source_order_entity_id'] ?? ''),
            currencyCode: $currency,
            customerEmail: $email,
            customerFirstname: $this->nullableString($billing['firstname'] ?? null),
            customerLastname: $this->nullableString($billing['lastname'] ?? null),
            isVirtual: $isVirtual,
            billingAddress: $billing,
            // A virtual order carries no shipment; a missing shipping block falls back to billing.
            shippingAddress: $isVirtual ? null : ($shipping !== [] ? $shipping : $billing),
            shippingMethod: $this->nullableString($order['shipping_method'] ?? null),
            shippingDescription: $this->nullableString($order['shipping_description'] ?? null),
            items: $items,
            totals: $totals,
            paymentMethod: $this->resolvePaymentMethod($order)
        );
    }

    /**
     * @return array<string, mixed>
     * @throws MaterialisationException
     */
    private function decodePayload(string $json): array
    {
        if (trim($json) === '') {
            throw new MaterialisationException(
                'Staged import carries no order payload.',
                MaterialisationException::REASON_BAD_PAYLOAD,
                false
            );
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new MaterialisationException(
                'Staged order payload is not valid JSON.',
                MaterialisationException::REASON_BAD_PAYLOAD,
                false
            );
        }
        return $decoded;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $order
     * @throws MaterialisationException
     */
    private function resolveStoreId(array $row, array $order): int
    {
        // Exact store mapping: destination store_id == source store_id (GOLDEN_CUSTOMER_02). Prefer the
        // authoritative identity column; fall back to the payload's store_id.
        $candidate = $this->firstNonEmpty(
            (string) ($row['source_store_id'] ?? ''),
            (string) ($order['store_id'] ?? '')
        );
        if ($candidate === '' || !is_numeric($candidate)) {
            throw new MaterialisationException(
                'Order has no numeric destination store_id.',
                MaterialisationException::REASON_MISSING_FIELD,
                false
            );
        }
        return (int) $candidate;
    }

    /**
     * @param array<string, mixed> $order
     * @return array<int, array<string, mixed>>
     * @throws MaterialisationException
     */
    private function buildItems(array $order): array
    {
        $rawItems = $order['line_items'] ?? [];
        if (!is_array($rawItems) || $rawItems === []) {
            throw new MaterialisationException(
                'Order has no line items.',
                MaterialisationException::REASON_NO_ITEMS,
                false
            );
        }

        $items = [];
        foreach ($rawItems as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $sku = trim((string) ($raw['sku'] ?? ''));
            $qty = (float) ($raw['qty_ordered'] ?? $raw['qty'] ?? 0);
            if ($sku === '') {
                throw new MaterialisationException(
                    'A line item is missing its SKU.',
                    MaterialisationException::REASON_MISSING_FIELD,
                    false
                );
            }
            if ($qty <= 0) {
                throw new MaterialisationException(
                    sprintf('Line item %s has a non-positive quantity.', $sku),
                    MaterialisationException::REASON_MISSING_FIELD,
                    false
                );
            }
            $price = (float) ($raw['price'] ?? 0);
            $items[] = [
                'sku' => $sku,
                'name' => $this->nullableString($raw['name'] ?? null),
                'qty' => $qty,
                'price' => $price,
                'row_total' => (float) ($raw['row_total'] ?? ($price * $qty)),
                'tax_amount' => (float) ($raw['tax_amount'] ?? 0),
                'discount_amount' => (float) ($raw['discount_amount'] ?? 0),
            ];
        }

        if ($items === []) {
            throw new MaterialisationException(
                'Order has no usable line items.',
                MaterialisationException::REASON_NO_ITEMS,
                false
            );
        }
        return $items;
    }

    /**
     * @param array<string, mixed> $order
     * @return array{subtotal:float,shipping:float,tax:float,discount:float,grand_total:float,currency:string}
     */
    private function buildTotals(array $order): array
    {
        $t = $this->arrayField($order, 'totals');
        return [
            'subtotal' => (float) ($t['subtotal'] ?? 0),
            'shipping' => (float) ($t['shipping_amount'] ?? 0),
            'tax' => (float) ($t['tax_amount'] ?? 0),
            'discount' => (float) ($t['discount_amount'] ?? 0),
            'grand_total' => (float) ($t['grand_total'] ?? 0),
            'currency' => (string) ($t['order_currency_code'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $order
     */
    private function resolvePaymentMethod(array $order): string
    {
        $payment = $this->arrayField($order, 'payment');
        $method = trim((string) ($payment['method'] ?? ''));
        // Default to an offline method present on stock Magento — the replicated order is recorded as
        // already-paid metadata; no funds are captured in B (GOLDEN_CUSTOMER_02).
        return $method !== '' ? $method : 'checkmo';
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function arrayField(array $source, string $key): array
    {
        $value = $source[$key] ?? null;
        return is_array($value) ? $value : [];
    }

    private function firstNonEmpty(string ...$values): string
    {
        foreach ($values as $value) {
            if (trim($value) !== '') {
                return trim($value);
            }
        }
        return '';
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $string = trim((string) $value);
        return $string === '' ? null : $string;
    }

    private function toBool(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }
}
