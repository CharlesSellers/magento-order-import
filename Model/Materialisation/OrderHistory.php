<?php
declare(strict_types=1);

namespace Venuno\OrderImport\Model\Materialisation;

/** Versioned, scalar-allowlisted snapshot. No payment actions or destination ids may come from it. */
final class OrderHistory
{
    private function __construct(public readonly array $data, public readonly array $items) {}

    public static function contract(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/../../etc/order-history-v1.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function fromDraft(OrderDraft $draft): self
    {
        $h = $draft->sourceHistory;
        if (($h['version'] ?? null) !== 1 || ($h['complete'] ?? null) !== true) self::fail('A complete version 1 source-history snapshot is required.');
        $contract = self::contract();
        foreach (['order', 'payment'] as $key) self::validateRow($h[$key] ?? null, $contract[$key], $key);
        $order = $h['order'];
        foreach (['entity_id' => $draft->sourceEntityId, 'store_id' => (string) $draft->storeId, 'increment_id' => $draft->sourceIncrementId] as $field => $value) {
            if ((string) ($order[$field] ?? '') !== $value) self::fail('History order identity differs from intake: ' . $field);
        }
        foreach (['created_at', 'updated_at', 'state', 'status', 'order_currency_code'] as $field) {
            if (($order[$field] ?? null) !== ($draft->sourceHeader[$field] ?? null)) self::fail('History/header mismatch: ' . $field);
        }
        foreach (['base_currency_code', 'order_currency_code', 'store_currency_code', 'global_currency_code'] as $field) {
            if (!is_string($order[$field] ?? null) || !preg_match('/^[A-Z]{3}$/D', $order[$field])) self::fail('Missing history currency: ' . $field);
        }
        foreach (['base_grand_total','grand_total','base_subtotal','subtotal','base_shipping_amount','shipping_amount','base_tax_amount','tax_amount','base_discount_amount','discount_amount'] as $field) self::number($order[$field] ?? null, $field);
        foreach (['grand_total' => 'grand_total', 'subtotal'=>'subtotal', 'shipping_amount'=>'shipping', 'tax_amount'=>'tax', 'discount_amount'=>'discount'] as $field=>$total) {
            self::sameNumber($order[$field], $draft->totals[$total], 'History total differs from canonical order: ' . $field);
        }
        if (($h['payment']['method'] ?? null) !== $draft->paymentMethod) self::fail('History payment method differs from order.');
        $items = [];
        foreach (self::rows($h['items'] ?? null, 'items') as $item) {
            self::validateRow($item, $contract['items'], 'item');
            $id = self::id($item['item_id'] ?? null);
            if (isset($items[$id]) || self::id($item['order_id'] ?? null) !== $draft->sourceEntityId) self::fail('Duplicate or foreign history order item.');
            foreach (['qty_ordered','qty_invoiced','qty_shipped','qty_refunded','qty_canceled','price','base_price','row_total','base_row_total'] as $field) self::number($item[$field] ?? null, $field);
            if ((float) $item['qty_ordered'] <= 0) self::fail('History order quantity must be positive.');
            foreach (['qty_invoiced','qty_shipped','qty_refunded','qty_canceled'] as $field) {
                if ((float) $item[$field] < 0 || (float) $item[$field] > (float) $item['qty_ordered'] + 0.0001) self::fail('History quantity is outside the ordered quantity.');
            }
            $items[$id] = $item;
        }
        if (count($items) !== count($draft->items)) self::fail('History item collection is incomplete.');
        $seen = [];
        foreach ($draft->items as $item) {
            $id = self::id($item['source_item_id'] ?? null);
            $historical = $items[$id] ?? null;
            if (!$historical || isset($seen[$id]) || ($historical['sku'] ?? null) !== $item['sku']) self::fail('Canonical/history item identity mismatch.');
            $seen[$id] = true;
            self::sameNumber($historical['qty_ordered'], $item['qty'], 'Canonical/history quantity mismatch.');
            foreach (['price','row_total'] as $field) self::sameNumber($historical[$field], $item[$field], 'Canonical/history price mismatch.');
        }
        foreach ($items as $id=>$item) {
            $visited = [$id=>true];
            while (!empty($item['parent_item_id'])) {
                $parent = self::id($item['parent_item_id']);
                if (!isset($items[$parent]) || isset($visited[$parent])) self::fail('Missing or cyclic parent order item.');
                $visited[$parent] = true;
                $item = $items[$parent];
            }
        }
        $invoiceIds = [];
        foreach (['invoices'=>'qty_invoiced','shipments'=>'qty_shipped','creditmemos'=>'qty_refunded'] as $type=>$quantityField) {
            $documents = self::rows($h[$type] ?? null, $type);
            $documentIds = [];
            $qty = array_fill_keys(array_keys($items), 0.0);
            $total = $baseTotal = 0.0;
            foreach ($documents as $document) {
                self::validateRow(array_diff_key($document, array_flip(['items','comments','tracks'])), $contract[$type], $type);
                $documentId = self::id($document['entity_id'] ?? null);
                if (isset($documentIds[$documentId]) || self::id($document['order_id'] ?? null) !== $draft->sourceEntityId
                    || (int) ($document['store_id'] ?? 0) !== $draft->storeId) self::fail('Duplicate or foreign sales document.');
                $documentIds[$documentId] = true;
                if (!is_string($document['increment_id'] ?? null) || $document['increment_id'] === '') self::fail('Sales document number is missing.');
                self::date($document['created_at'] ?? null);
                self::date($document['updated_at'] ?? null);
                if ($type === 'invoices') $invoiceIds[$documentId] = true;
                if ($type === 'creditmemos' && !empty($document['invoice_id']) && !isset($invoiceIds[self::id($document['invoice_id'])])) self::fail('Credit memo references an invoice outside its snapshot.');
                $active = $type === 'shipments' || ($type === 'invoices' ? (int) ($document['state'] ?? 0) !== 3 : (int) ($document['state'] ?? 0) === 2);
                if ($type !== 'shipments') {
                    foreach (['grand_total','base_grand_total'] as $field) self::number($document[$field] ?? null, $field);
                    if ($active) { $total += (float) $document['grand_total']; $baseTotal += (float) $document['base_grand_total']; }
                }
                $itemKey = ['invoices'=>'invoice_items','shipments'=>'shipment_items','creditmemos'=>'creditmemo_items'][$type];
                $childIds = [];
                foreach (self::rows($document['items'] ?? null, $itemKey) as $child) {
                    self::validateRow($child, $contract[$itemKey], $itemKey);
                    $childId = self::id($child['entity_id'] ?? null);
                    $orderItemId = self::id($child['order_item_id'] ?? null);
                    if (isset($childIds[$childId]) || !isset($items[$orderItemId]) || self::id($child['parent_id'] ?? null) !== $documentId) self::fail('Sales document item has an invalid relationship.');
                    $childIds[$childId] = true;
                    if (isset($child['sku']) && $child['sku'] !== $items[$orderItemId]['sku']) self::fail('Sales document item SKU mismatch.');
                    self::number($child['qty'] ?? null, 'qty');
                    if ((float) $child['qty'] < 0) self::fail('Negative sales document quantity.');
                    if ($active) $qty[$orderItemId] += (float) $child['qty'];
                }
                foreach (self::rows($document['comments'] ?? null, 'comments') as $comment) {
                    self::validateRow($comment, $contract['comments'], 'comment');
                    if (self::id($comment['parent_id'] ?? null) !== $documentId) self::fail('Foreign document comment.');
                    self::date($comment['created_at'] ?? null);
                }
                if ($type === 'shipments') foreach (self::rows($document['tracks'] ?? null, 'tracks') as $track) {
                    self::validateRow($track, $contract['tracks'], 'track');
                    if (self::id($track['parent_id'] ?? null) !== $documentId || self::id($track['order_id'] ?? null) !== $draft->sourceEntityId) self::fail('Foreign shipment track.');
                }
            }
            foreach ($items as $id=>$item) self::sameNumber($item[$quantityField], $qty[$id], 'Incomplete ' . $type . ' for an order item.');
            if ($type !== 'shipments') {
                $suffix = $type === 'invoices' ? 'invoiced' : 'refunded';
                self::sameNumber($order['total_' . $suffix] ?? 0, $total, 'Incomplete ' . $type . ' order total.');
                self::sameNumber($order['base_total_' . $suffix] ?? 0, $baseTotal, 'Incomplete ' . $type . ' base total.');
            }
        }
        foreach (self::rows($h['status_histories'] ?? null, 'status history') as $comment) {
            self::validateRow($comment, $contract['comments'], 'status history');
            if (self::id($comment['parent_id'] ?? null) !== $draft->sourceEntityId) self::fail('Foreign status history.');
            self::date($comment['created_at'] ?? null);
        }
        return new self($h, $items);
    }

    private static function rows(mixed $rows, string $name): array
    {
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > 10000) self::fail('Missing or unbounded ' . $name . ' collection.');
        foreach ($rows as $row) if (!is_array($row)) self::fail('Invalid ' . $name . ' row.');
        return $rows;
    }
    private static function validateRow(mixed $row, array $allowed, string $name): void
    {
        if (!is_array($row) || array_diff(array_keys($row), $allowed)) self::fail('Invalid or unrecognised ' . $name . ' fields.');
        foreach ($row as $key=>$value) {
            if ($value !== null && (!is_scalar($value) || (is_float($value) && !is_finite($value)))) self::fail('History fields must be finite scalars.');
            if (is_string($value) && strlen($value) > 65535) self::fail('Oversized history field.');
            if ($value !== null && preg_match('/(^base_|^qty$|^qty_|^price$|^row_total|^grand_total|^subtotal|^tax_|^discount_|^shipping_(amount|tax|discount|incl)|^amount_|^total_|^weight$)/', $key)
                && !str_ends_with($key, 'currency_code') && !str_ends_with($key, 'description')) self::number($value, $key);
        }
    }
    private static function id(mixed $value): string
    {
        if ((!is_string($value) && !is_int($value)) || !preg_match('/^[1-9][0-9]*$/D', (string) $value)) self::fail('Invalid source history id.');
        return (string) $value;
    }
    private static function number(mixed $value, string $field): void
    {
        if ((!is_int($value) && !is_float($value) && !is_string($value)) || !is_numeric($value) || !is_finite((float) $value)) self::fail('Invalid numeric history field: ' . $field);
    }
    private static function sameNumber(mixed $a, mixed $b, string $message): void
    {
        self::number($a, 'comparison'); self::number($b, 'comparison');
        if (abs((float) $a - (float) $b) > 0.00011) self::fail($message);
    }
    private static function date(mixed $value): void
    {
        if (!is_string($value)) self::fail('Missing source history date.');
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
        if (!$parsed || $parsed->format('Y-m-d H:i:s') !== $value) self::fail('Invalid source history date.');
    }
    private static function fail(string $message): never
    {
        throw new MaterialisationException($message, 'source_history_invalid', false);
    }
}
