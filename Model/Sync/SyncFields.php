<?php
declare(strict_types=1);

namespace Venuno\OrderImport\Model\Sync;

use Venuno\OrderImport\Model\Materialisation\OrderHistory;

/**
 * Which fields a sync may overwrite (legacy wins) and which it must leave alone.
 *
 * Synced: the order-history-v1 contract's financial/state header fields, the order's customer snapshot
 * fields, address fields, order-line quantity/amount fields, payment accounting fields, and document
 * lifecycle fields. Never synced: identities and foreign keys (entity/customer/product/quote/address
 * ids), currencies and created_at (a difference fails or warns instead), and destination-local
 * artefacts that differ by construction (product_options, base_cost, gift-message flags, card metadata,
 * payment additional_information, weee, applied_rule_ids, customer_gender, Stripe-only columns).
 */
final class SyncFields
{
    public const STATES = ['new', 'pending_payment', 'processing', 'complete', 'closed', 'canceled', 'holded', 'payment_review'];

    public const CURRENCIES = ['base_currency_code', 'order_currency_code', 'store_currency_code', 'global_currency_code'];

    private const ORDER_EXCLUDED = ['entity_id', 'store_id', 'increment_id', 'created_at', 'state', 'status',
        'base_currency_code', 'order_currency_code', 'store_currency_code', 'global_currency_code',
        'base_to_global_rate', 'base_to_order_rate', 'store_to_base_rate', 'store_to_order_rate'];

    public const ORDER_CUSTOMER = ['customer_email', 'customer_firstname', 'customer_lastname', 'customer_middlename',
        'customer_prefix', 'customer_suffix', 'customer_taxvat', 'customer_note'];

    public const ADDRESS = ['prefix', 'firstname', 'middlename', 'lastname', 'suffix', 'company', 'street', 'city',
        'region', 'region_id', 'postcode', 'country_id', 'telephone', 'fax', 'email', 'vat_id'];

    public const ITEM = ['name', 'weight', 'row_weight', 'qty_invoiced', 'qty_shipped', 'qty_refunded', 'qty_canceled',
        'price', 'base_price', 'original_price', 'base_original_price', 'tax_percent', 'tax_amount', 'base_tax_amount',
        'tax_invoiced', 'base_tax_invoiced', 'tax_refunded', 'base_tax_refunded', 'tax_canceled',
        'discount_percent', 'discount_amount', 'base_discount_amount', 'discount_invoiced', 'base_discount_invoiced',
        'discount_refunded', 'base_discount_refunded', 'amount_refunded', 'base_amount_refunded',
        'row_total', 'base_row_total', 'row_invoiced', 'base_row_invoiced', 'price_incl_tax', 'base_price_incl_tax',
        'row_total_incl_tax', 'base_row_total_incl_tax', 'discount_tax_compensation_amount',
        'base_discount_tax_compensation_amount', 'discount_tax_compensation_invoiced',
        'base_discount_tax_compensation_invoiced', 'discount_tax_compensation_refunded',
        'base_discount_tax_compensation_refunded', 'discount_tax_compensation_canceled',
        'locked_do_invoice', 'locked_do_ship', 'updated_at'];

    /** Lifecycle fields of an already-present document that may move on in legacy (legacy wins). */
    public const DOCUMENT_LIFECYCLE = [
        'invoices' => ['state', 'can_void_flag', 'is_used_for_refund', 'base_total_refunded', 'transaction_id', 'updated_at'],
        'shipments' => ['shipment_status', 'updated_at'],
        'creditmemos' => ['state', 'creditmemo_status', 'transaction_id', 'updated_at'],
    ];

    /** Content of an already-present document. Any difference fails closed (never rewrite a posted document). */
    public const DOCUMENT_CONTENT = [
        'invoices' => ['grand_total', 'base_grand_total', 'subtotal', 'tax_amount', 'shipping_amount', 'discount_amount'],
        'shipments' => ['total_qty'],
        'creditmemos' => ['grand_total', 'base_grand_total', 'subtotal', 'tax_amount', 'shipping_amount', 'adjustment'],
    ];

    /** @return list<string> */
    public static function order(): array
    {
        $contract = OrderHistory::contract();
        $fields = array_values(array_diff($contract['order'], self::ORDER_EXCLUDED));
        return array_values(array_unique(array_merge($fields, self::ORDER_CUSTOMER)));
    }

    /** @return list<string> */
    public static function payment(): array
    {
        return array_values(array_diff(OrderHistory::contract()['payment'], ['method']));
    }

    /** Contract allowlist for inserting a document-type row (header/items/comments/tracks). */
    public static function contract(string $key): array
    {
        return OrderHistory::contract()[$key];
    }

    /** Equal for sync purposes: numeric tolerance, and null/'' are the same "empty". */
    public static function same(mixed $a, mixed $b): bool
    {
        $a = $a === '' ? null : $a;
        $b = $b === '' ? null : $b;
        if ($a === null || $b === null) {
            return $a === $b;
        }
        if (is_numeric($a) && is_numeric($b)) {
            return abs((float) $a - (float) $b) < 0.00005;
        }
        return (string) $a === (string) $b;
    }

    /** Text equality ignoring leading/trailing whitespace (legacy stores e.g. "GS Associates "; same value). */
    public static function sameText(mixed $a, mixed $b): bool
    {
        return self::same(is_string($a) ? trim($a) : $a, is_string($b) ? trim($b) : $b);
    }

    /** Destination-authored status-history comments (import note, reconciliation and sync notes). */
    public static function isVenunoComment(array $row): bool
    {
        $comment = (string) ($row['comment'] ?? '');
        return str_starts_with($comment, 'Venuno ')
            || (str_starts_with($comment, 'Imported from ') && str_contains($comment, ' via Venuno.'));
    }
}
