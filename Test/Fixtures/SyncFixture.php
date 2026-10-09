<?php
declare(strict_types=1);

namespace Venuno\OrderImport\Test\Fixtures;

use Venuno\OrderImport\Model\Sync\SyncContext;

/**
 * Synthetic legacy/destination snapshots shaped like {@see \Venuno\OrderImport\Model\Sync\OrderSnapshotReader}
 * output: a two-line purchase-order order on store 25 awaiting approval, with helpers that move legacy
 * on the way Jangro orders do (approval, invoices, shipments with tracking, refunds, comments).
 */
final class SyncFixture
{
    public const LEGACY_ID = 5000;
    public const DEST_ID = 600;

    public static function legacy(): array
    {
        $at = '2026-10-01 09:00:00';
        $currencies = array_fill_keys(['base_currency_code', 'order_currency_code', 'store_currency_code', 'global_currency_code'], 'GBP');
        $order = $currencies + [
            'entity_id' => self::LEGACY_ID, 'store_id' => 25, 'increment_id' => '25000001', 'state' => 'new', 'status' => 'awaiting_approval',
            'created_at' => $at, 'updated_at' => $at, 'customer_id' => 77, 'customer_email' => 'buyer@example.test',
            'customer_firstname' => 'Pat', 'customer_lastname' => 'Buyer', 'customer_gender' => null,
            'grand_total' => 36.0, 'base_grand_total' => 36.0, 'subtotal' => 30.0, 'base_subtotal' => 30.0,
            'tax_amount' => 6.0, 'base_tax_amount' => 6.0, 'shipping_amount' => 0.0, 'base_shipping_amount' => 0.0,
            'discount_amount' => 0.0, 'base_discount_amount' => 0.0, 'total_invoiced' => null, 'base_total_invoiced' => null,
            'total_paid' => null, 'base_total_paid' => null, 'total_refunded' => null, 'base_total_refunded' => null,
            'total_due' => 36.0, 'base_total_due' => 36.0, 'total_qty_ordered' => 3.0, 'applied_rule_ids' => '1,2',
            'send_email' => 1, 'email_sent' => 1,
        ];
        $item = fn (int $id, string $sku, float $qty, float $price) => [
            'item_id' => $id, 'order_id' => self::LEGACY_ID, 'parent_item_id' => null, 'store_id' => 25, 'product_id' => $id + 100,
            'sku' => $sku, 'name' => 'Product ' . $sku, 'created_at' => $at, 'updated_at' => $at, 'product_options' => 'a:0:{}',
            'base_cost' => 1.0, 'qty_ordered' => $qty, 'qty_invoiced' => 0.0, 'qty_shipped' => 0.0, 'qty_refunded' => 0.0, 'qty_canceled' => 0.0,
            'price' => $price, 'base_price' => $price, 'row_total' => $qty * $price, 'base_row_total' => $qty * $price,
            'tax_amount' => $qty * $price * 0.2, 'base_tax_amount' => $qty * $price * 0.2, 'row_invoiced' => 0.0, 'base_row_invoiced' => 0.0,
            'tax_invoiced' => 0.0, 'base_tax_invoiced' => 0.0, 'amount_refunded' => 0.0, 'base_amount_refunded' => 0.0, 'weight' => 1.0,
        ];
        $address = fn (int $id, string $type) => ['entity_id' => $id, 'parent_id' => self::LEGACY_ID, 'address_type' => $type,
            'prefix' => 'SITE1', 'firstname' => 'Pat', 'lastname' => 'Buyer', 'company' => 'Acme Ltd', 'street' => "1 High Street\nUnit 2",
            'city' => 'Leeds', 'region' => null, 'region_id' => null, 'postcode' => 'LS1 1AA', 'country_id' => 'GB', 'telephone' => '0113',
            'email' => 'buyer@example.test', 'customer_address_id' => 55];
        return [
            'order' => $order,
            'addresses' => ['billing' => $address(9001, 'billing'), 'shipping' => $address(9002, 'shipping')],
            'items' => [$item(7001, 'SKU-A', 2.0, 10.0), $item(7002, 'SKU-B', 1.0, 10.0)],
            'payment' => ['entity_id' => 9100, 'parent_id' => self::LEGACY_ID, 'method' => 'purchaseorder', 'po_number' => 'PO-1',
                'amount_ordered' => 36.0, 'base_amount_ordered' => 36.0, 'amount_paid' => null, 'base_amount_paid' => null,
                'cc_exp_year' => '0', 'additional_information' => '{"a":1}'],
            'invoices' => [], 'shipments' => [], 'creditmemos' => [],
            'status_histories' => [],
        ];
    }

    /** The destination copy of $legacy as the importer created it (destination ids, import comment, artefacts). */
    public static function dest(array $legacy): array
    {
        $dest = $legacy;
        $dest['order']['entity_id'] = self::DEST_ID;
        $dest['order']['customer_id'] = 3065;
        $dest['order']['applied_rule_ids'] = null;
        $dest['order']['customer_gender'] = 0;
        $map = [];
        foreach ($dest['items'] as $n => &$item) {
            $map[$item['item_id']] = 800 + $n;
            $item['item_id'] = 800 + $n;
            $item['order_id'] = self::DEST_ID;
            $item['product_options'] = null;
            $item['base_cost'] = null;
        }
        unset($item);
        foreach (['billing' => 901, 'shipping' => 902] as $type => $id) {
            $dest['addresses'][$type]['entity_id'] = $id;
            $dest['addresses'][$type]['parent_id'] = self::DEST_ID;
            $dest['addresses'][$type]['customer_address_id'] = null;
        }
        $dest['payment']['entity_id'] = 950;
        $dest['payment']['parent_id'] = self::DEST_ID;
        $dest['payment']['cc_exp_year'] = null;
        $dest['payment']['additional_information'] = null;
        $docId = 3000;
        foreach (['invoices', 'shipments', 'creditmemos'] as $key) {
            foreach ($dest[$key] as &$doc) {
                $doc['entity_id'] = ++$docId;
                $doc['order_id'] = self::DEST_ID;
                foreach ($doc['items'] as &$child) {
                    $child['order_item_id'] = $map[$child['order_item_id']];
                    $child['parent_id'] = $docId;
                }
                unset($child);
                foreach ($doc['tracks'] ?? [] as $t => $track) {
                    $doc['tracks'][$t]['parent_id'] = $docId;
                    $doc['tracks'][$t]['order_id'] = self::DEST_ID;
                }
            }
            unset($doc);
        }
        $dest['status_histories'][] = ['entity_id' => 1, 'parent_id' => self::DEST_ID, 'created_at' => '2026-10-01 10:00:00',
            'status' => $legacy['order']['status'], 'comment' => 'Imported from magento order 25000001 (entity_id 5000) via Venuno.',
            'entity_name' => 'order', 'is_customer_notified' => 0, 'is_visible_on_front' => 0];
        return $dest;
    }

    public static function approve(array $l, string $at = '2026-10-02 08:00:00'): array
    {
        $l['order']['status'] = 'approved';
        $l['order']['updated_at'] = $at;
        return $l;
    }

    /** Invoice every line in full (Jangro PO orders are invoiced by the back office). */
    public static function invoice(array $l, string $number = 'INV-25-1', int $id = 6100, string $at = '2026-10-03 08:00:00'): array
    {
        $items = [];
        foreach ($l['items'] as &$item) {
            $item['qty_invoiced'] = $item['qty_ordered'];
            $item['row_invoiced'] = $item['row_total'];
            $item['base_row_invoiced'] = $item['base_row_total'];
            $item['tax_invoiced'] = $item['tax_amount'];
            $item['base_tax_invoiced'] = $item['base_tax_amount'];
            $item['updated_at'] = $at;
            $items[] = ['entity_id' => $id * 10 + count($items), 'parent_id' => $id, 'order_item_id' => $item['item_id'], 'product_id' => $item['product_id'],
                'sku' => $item['sku'], 'name' => $item['name'], 'qty' => $item['qty_ordered'], 'price' => $item['price'], 'base_price' => $item['price'],
                'row_total' => $item['row_total'], 'base_row_total' => $item['base_row_total'], 'tax_amount' => $item['tax_amount']];
        }
        unset($item);
        $o = &$l['order'];
        foreach (['total_invoiced', 'base_total_invoiced', 'total_paid', 'base_total_paid'] as $f) {
            $o[$f] = $o['grand_total'];
        }
        $o['total_due'] = $o['base_total_due'] = 0.0;
        $o['state'] = 'processing';
        $o['status'] = 'processing';
        $o['updated_at'] = $at;
        $l['payment']['amount_paid'] = $l['payment']['base_amount_paid'] = $o['grand_total'];
        $l['invoices'][] = ['entity_id' => $id, 'order_id' => self::LEGACY_ID, 'store_id' => 25, 'increment_id' => $number, 'state' => 2,
            'grand_total' => $o['grand_total'], 'base_grand_total' => $o['grand_total'], 'subtotal' => $o['subtotal'], 'base_subtotal' => $o['subtotal'],
            'tax_amount' => $o['tax_amount'], 'base_tax_amount' => $o['tax_amount'], 'shipping_amount' => 0.0, 'discount_amount' => 0.0,
            'billing_address_id' => 9001, 'shipping_address_id' => 9002, 'order_currency_code' => 'GBP', 'base_currency_code' => 'GBP',
            'created_at' => $at, 'updated_at' => $at, 'send_email' => 1, 'email_sent' => 1, 'total_qty' => 3.0,
            'items' => $items, 'comments' => [['entity_id' => $id + 1, 'parent_id' => $id, 'comment' => 'Invoiced by office', 'created_at' => $at,
                'is_customer_notified' => 0, 'is_visible_on_front' => 0]]];
        return $l;
    }

    /** Ship $qty of the first line, optionally with a tracking number. */
    public static function ship(array $l, float $qty = 2.0, ?string $track = 'TRACK-1', string $number = 'SHP-25-1', int $id = 6200, string $at = '2026-10-04 08:00:00'): array
    {
        $l['items'][0]['qty_shipped'] += $qty;
        $l['items'][0]['updated_at'] = $at;
        $l['order']['updated_at'] = $at;
        $l['shipments'][] = ['entity_id' => $id, 'order_id' => self::LEGACY_ID, 'store_id' => 25, 'increment_id' => $number, 'total_qty' => $qty,
            'billing_address_id' => 9001, 'shipping_address_id' => 9002, 'customer_id' => 77, 'created_at' => $at, 'updated_at' => $at,
            'send_email' => 1, 'email_sent' => 1,
            'items' => [['entity_id' => $id * 10, 'parent_id' => $id, 'order_item_id' => $l['items'][0]['item_id'], 'product_id' => $l['items'][0]['product_id'],
                'sku' => $l['items'][0]['sku'], 'name' => $l['items'][0]['name'], 'qty' => $qty, 'price' => $l['items'][0]['price']]],
            'comments' => [],
            'tracks' => $track === null ? [] : [['entity_id' => $id + 1, 'parent_id' => $id, 'order_id' => self::LEGACY_ID, 'track_number' => $track,
                'title' => 'DPD', 'carrier_code' => 'custom', 'created_at' => $at, 'updated_at' => $at]]];
        return $l;
    }

    /** Refund line 2 in full against the first invoice. */
    public static function refund(array $l, string $number = 'CM-25-1', int $id = 6300, string $at = '2026-10-05 08:00:00'): array
    {
        $item = &$l['items'][1];
        $item['qty_refunded'] = $item['qty_ordered'];
        $item['amount_refunded'] = $item['base_amount_refunded'] = $item['row_total'];
        $item['updated_at'] = $at;
        $total = $item['row_total'] + $item['tax_amount'];
        $l['order']['total_refunded'] = $l['order']['base_total_refunded'] = $total;
        $l['order']['updated_at'] = $at;
        $l['creditmemos'][] = ['entity_id' => $id, 'order_id' => self::LEGACY_ID, 'store_id' => 25, 'increment_id' => $number, 'state' => 2,
            'invoice_id' => $l['invoices'][0]['entity_id'], 'grand_total' => $total, 'base_grand_total' => $total, 'subtotal' => $item['row_total'],
            'tax_amount' => $item['tax_amount'], 'shipping_amount' => 0.0, 'adjustment' => 0.0, 'billing_address_id' => 9001, 'shipping_address_id' => 9002,
            'created_at' => $at, 'updated_at' => $at, 'send_email' => 1, 'email_sent' => 1,
            'items' => [['entity_id' => $id * 10, 'parent_id' => $id, 'order_item_id' => $item['item_id'], 'product_id' => $item['product_id'],
                'sku' => $item['sku'], 'name' => $item['name'], 'qty' => $item['qty_ordered'], 'price' => $item['price'], 'row_total' => $item['row_total']]],
            'comments' => []];
        return $l;
    }

    public static function context(array $pairs = [['approved', 'new'], ['awaiting_approval', 'new'], ['pending', 'new'], ['processing', 'processing'],
        ['complete', 'complete'], ['canceled', 'canceled']], array $pending = [], array $taken = []): SyncContext
    {
        $map = $statuses = [];
        foreach ($pairs as [$status, $state]) {
            $map[$status][$state] = true;
            $statuses[$status] = true;
        }
        $pendingMap = [];
        foreach ($pending as [$status, $state]) {
            $pendingMap[$status][$state] = true;
            $statuses[$status] = true;
        }
        return new SyncContext($map, $statuses, fn (string $t, int $s, string $n, int $o): bool => in_array($n, $taken, true), $pendingMap);
    }
}
