<?php
declare(strict_types=1);

namespace Venuno\OrderImport\Model\Sync;

use Venuno\OrderImport\Model\Materialisation\SourceOrderMetadata;

/**
 * Pure diff of a legacy order snapshot (source of truth) against its already-imported destination order.
 * Produces only additive document operations and field updates; never deletes or rewrites a posted
 * document. Every inconsistency fails closed with a stable reason code ({@see SyncException}).
 */
class SyncPlanner
{
    private const DOCUMENTS = ['invoices' => 'sales_invoice', 'shipments' => 'sales_shipment', 'creditmemos' => 'sales_creditmemo'];
    private const QTY_FIELD = ['invoices' => 'qty_invoiced', 'shipments' => 'qty_shipped', 'creditmemos' => 'qty_refunded'];

    /**
     * @param array $legacy snapshot from {@see OrderSnapshotReader} (or {@see PayloadSnapshot}); a field absent
     *                      from a legacy row is "unknown" and never synced
     * @param array $dest   destination snapshot from {@see OrderSnapshotReader}
     * @throws SyncException
     */
    public function plan(array $legacy, array $dest, SyncContext $context, int $legacyOrderId): SyncPlan
    {
        $L = $legacy['order'];
        $M = $dest['order'];
        $magentoOrderId = (int) $M['entity_id'];
        $storeId = (int) $M['store_id'];
        if ((string) $L['increment_id'] !== (string) $M['increment_id'] || (int) $L['store_id'] !== $storeId) {
            throw new SyncException('Legacy and destination order identity differ (increment_id/store_id).', 'identity_mismatch');
        }
        foreach (SyncFields::CURRENCIES as $field) {
            if (array_key_exists($field, $L) && !SyncFields::same($L[$field], $M[$field] ?? null)) {
                throw new SyncException('Order currency differs: ' . $field, 'currency_mismatch');
            }
        }
        $this->validateLegacy($legacy);
        $warnings = [];
        $categories = [];
        if (array_key_exists('created_at', $L) && !SyncFields::same($L['created_at'], $M['created_at'] ?? null)) {
            $warnings[] = 'created_at differs (not synced)';
        }

        // --- order items: identical line structure is required; map legacy -> destination ids.
        $itemMap = $this->mapItems($legacy['items'], $dest['items']);

        // --- state/status (legacy wins, with the importer's verified state normalisation)
        $updates = [];
        $sourceState = (string) $L['state'];
        $status = (string) $L['status'];
        $state = SourceOrderMetadata::normaliseState($sourceState, $status);
        $orderFields = [];
        if ($state !== (string) $M['state'] || $status !== (string) $M['status']) {
            if (!in_array($state, SyncFields::STATES, true)) {
                throw new SyncException(sprintf('Legacy state "%s" is not a Magento state.', $state), 'state_unsupported');
            }
            if (!isset($context->statuses[$status])) {
                throw new SyncException(sprintf('Status "%s" does not exist on the destination.', $status), 'status_unknown');
            }
            if (!isset($context->pairs[$status][$state])) {
                if (!isset($context->pendingPairs[$status][$state])) {
                    throw new SyncException(sprintf('Status "%s" is not assigned to state "%s" on the destination.', $status, $state), 'status_unmapped');
                }
                $categories[] = 'needs_status_assignment:' . $state . '/' . $status;
            }
            if ($state !== (string) $M['state']) {
                $orderFields['state'] = ['from' => $M['state'], 'to' => $state];
            }
            if ($status !== (string) $M['status']) {
                $orderFields['status'] = ['from' => $M['status'], 'to' => $status];
            }
            $categories[] = 'state_status';
        }

        // --- order header fields
        $headerChanged = $customerChanged = false;
        foreach (SyncFields::order() as $field) {
            if (!array_key_exists($field, $L) || !array_key_exists($field, $M)) {
                continue;
            }
            $isCustomer = in_array($field, SyncFields::ORDER_CUSTOMER, true);
            if ($isCustomer ? SyncFields::sameText($L[$field], $M[$field]) : SyncFields::same($L[$field], $M[$field])) {
                continue;
            }
            $orderFields[$field] = ['from' => $M[$field], 'to' => $L[$field]];
            if (in_array($field, SyncFields::ORDER_CUSTOMER, true)) {
                $customerChanged = true;
            } elseif ($field !== 'updated_at') {
                $headerChanged = true;
            }
        }
        if ($headerChanged) {
            $categories[] = 'order_fields';
        }
        if ($customerChanged) {
            $categories[] = 'customer_fields';
        }

        // --- addresses
        $addressUpdates = [];
        foreach (['billing', 'shipping'] as $type) {
            $la = $legacy['addresses'][$type] ?? null;
            $ma = $dest['addresses'][$type] ?? null;
            if ($la === null && $ma === null) {
                continue;
            }
            if ($la === null || $ma === null) {
                throw new SyncException('The ' . $type . ' address exists on only one side.', 'address_missing');
            }
            $fields = [];
            foreach (SyncFields::ADDRESS as $field) {
                if (!array_key_exists($field, $la) || !array_key_exists($field, $ma) || SyncFields::sameText($la[$field], $ma[$field])) {
                    continue;
                }
                if ($field === 'company' && $this->isCompanyFallback($la, $ma)) {
                    continue; // destination requires a company; the importer stores "firstname lastname" when legacy has none
                }
                $fields[$field] = ['from' => $ma[$field], 'to' => $la[$field]];
            }
            if ($fields) {
                $addressUpdates[] = ['table' => 'sales_order_address', 'key' => 'entity_id', 'id' => (int) $ma['entity_id'], 'fields' => $fields];
            }
        }
        if ($addressUpdates) {
            $categories[] = 'address_fields';
        }

        // --- order lines
        $itemUpdates = [];
        $destItems = array_column($dest['items'], null, 'item_id');
        $itemsMaterial = false;
        foreach ($legacy['items'] as $li) {
            $mi = $destItems[$itemMap[(string) $li['item_id']]];
            $fields = [];
            foreach (SyncFields::ITEM as $field) {
                if (array_key_exists($field, $li) && array_key_exists($field, $mi) && !SyncFields::same($li[$field], $mi[$field])) {
                    $fields[$field] = ['from' => $mi[$field], 'to' => $li[$field]];
                    $itemsMaterial = $itemsMaterial || $field !== 'updated_at';
                }
            }
            if ($fields) {
                $itemUpdates[] = ['table' => 'sales_order_item', 'key' => 'item_id', 'id' => (int) $mi['item_id'], 'fields' => $fields];
            }
        }
        if ($itemsMaterial) {
            $categories[] = 'item_fields';
        }

        // --- payment
        $paymentUpdates = [];
        $lp = $legacy['payment'];
        $mp = $dest['payment'];
        if ($lp !== null) {
            if ($mp === null || (string) ($lp['method'] ?? '') !== (string) ($mp['method'] ?? '')) {
                throw new SyncException('Payment method differs or is missing on the destination.', 'payment_mismatch');
            }
            $fields = [];
            foreach (SyncFields::payment() as $field) {
                if (array_key_exists($field, $lp) && array_key_exists($field, $mp) && !SyncFields::same($lp[$field], $mp[$field])) {
                    $fields[$field] = ['from' => $mp[$field], 'to' => $lp[$field]];
                }
            }
            if ($fields) {
                $paymentUpdates[] = ['table' => 'sales_order_payment', 'key' => 'entity_id', 'id' => (int) $mp['entity_id'], 'fields' => $fields];
                $categories[] = 'payment_fields';
            }
        }

        // --- documents (additive only)
        $inserts = [];
        $documentUpdates = [];
        $invoiceMap = [];
        foreach (self::DOCUMENTS as $key => $table) {
            $destByNumber = [];
            foreach ($dest[$key] as $document) {
                $destByNumber[(string) $document['increment_id']] = $document;
            }
            $legacyNumbers = [];
            foreach ($legacy[$key] as $document) {
                $number = (string) $document['increment_id'];
                $legacyNumbers[$number] = true;
                $existing = $destByNumber[$number] ?? null;
                if ($existing === null) {
                    if (($context->numberTakenElsewhere)($table, $storeId, $number, $magentoOrderId)) {
                        throw new SyncException(sprintf('%s number %s already belongs to another destination order.', $table, $number), 'history_number_conflict');
                    }
                    $inserts[] = ['kind' => $key, 'parent' => null, 'source' => $document];
                    $categories[] = $key . '_added';
                    continue;
                }
                if ($key === 'invoices') {
                    $invoiceMap[(string) $document['entity_id']] = (int) $existing['entity_id'];
                }
                $this->assertSameDocument($key, $document, $existing, $itemMap);
                $fields = [];
                foreach (SyncFields::DOCUMENT_LIFECYCLE[$key] as $field) {
                    if (array_key_exists($field, $document) && array_key_exists($field, $existing) && !SyncFields::same($document[$field], $existing[$field])) {
                        $fields[$field] = ['from' => $existing[$field], 'to' => $document[$field]];
                    }
                }
                if ($fields) {
                    $documentUpdates[] = ['table' => $table, 'key' => 'entity_id', 'id' => (int) $existing['entity_id'], 'fields' => $fields];
                    $categories[] = $key . '_lifecycle';
                }
                foreach ($this->missingComments($document['comments'] ?? [], $existing['comments'] ?? [], $warnings, $key . ' ' . $number) as $comment) {
                    $inserts[] = ['kind' => $key . '_comment', 'parent' => (int) $existing['entity_id'], 'source' => $comment];
                    $categories[] = 'document_comments_added';
                }
                if ($key === 'shipments') {
                    $destTracks = [];
                    foreach ($existing['tracks'] ?? [] as $track) {
                        $destTracks[$this->trackKey($track)] = true;
                    }
                    $legacyTracks = [];
                    foreach ($document['tracks'] ?? [] as $track) {
                        $legacyTracks[$this->trackKey($track)] = true;
                        if (!isset($destTracks[$this->trackKey($track)])) {
                            $inserts[] = ['kind' => 'tracks', 'parent' => (int) $existing['entity_id'], 'source' => $track];
                            $categories[] = 'tracks_added';
                        }
                    }
                    if (array_diff_key($destTracks, $legacyTracks)) {
                        throw new SyncException('Destination shipment ' . $number . ' has tracking that legacy does not.', 'destination_only_track');
                    }
                }
            }
            foreach ($destByNumber as $number => $document) {
                if (!isset($legacyNumbers[(string) $number])) {
                    throw new SyncException(sprintf('Destination has %s %s that legacy does not.', $table, $number), 'destination_only_document');
                }
            }
        }

        // --- order status history (legacy comments missing on the destination)
        $legacyHistory = $legacy['status_histories'];
        $destHistory = array_values(array_filter($dest['status_histories'], fn ($row) => !SyncFields::isVenunoComment($row)));
        foreach ($this->missingComments($legacyHistory, $destHistory, $warnings, 'order', true) as $comment) {
            $inserts[] = ['kind' => 'status_histories', 'parent' => null, 'source' => $comment];
            $categories[] = 'status_history_added';
        }

        $others = array_merge($addressUpdates, $itemUpdates, $paymentUpdates, $documentUpdates);
        // Any change also carries legacy's updated_at so the order reads as "in sync" afterwards.
        if (($orderFields !== [] || $others !== [] || $inserts !== []) && array_key_exists('updated_at', $L)
            && !SyncFields::same($L['updated_at'], $M['updated_at'])) {
            $orderFields['updated_at'] = ['from' => $M['updated_at'], 'to' => $L['updated_at']];
        }
        $allUpdates = $orderFields !== []
            ? array_merge([['table' => 'sales_order', 'key' => 'entity_id', 'id' => $magentoOrderId, 'fields' => $orderFields]], $others)
            : $others;
        if ($categories === [] && $allUpdates !== []) {
            $categories[] = 'timestamps_only';
        }

        return new SyncPlan(
            $legacyOrderId,
            $magentoOrderId,
            (string) $M['increment_id'],
            $storeId,
            (string) ($L['updated_at'] ?? ''),
            self::fingerprint($dest),
            $state,
            $status,
            $allUpdates,
            $inserts,
            $itemMap,
            $invoiceMap,
            array_values(array_unique($categories)),
            $warnings
        );
    }

    public static function fingerprint(array $snapshot): string
    {
        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    /** Legacy must be internally consistent (documents add up to the order) before it may be copied. */
    private function validateLegacy(array $legacy): void
    {
        $items = [];
        foreach ($legacy['items'] as $item) {
            $items[(string) $item['item_id']] = $item;
        }
        $order = $legacy['order'];
        $invoiceIds = [];
        foreach (self::QTY_FIELD as $key => $qtyField) {
            $qty = array_fill_keys(array_keys($items), 0.0);
            $total = 0.0;
            foreach ($legacy[$key] as $document) {
                if ((int) ($document['store_id'] ?? $order['store_id']) !== (int) $order['store_id']) {
                    throw new SyncException('Legacy document belongs to another store.', 'legacy_inconsistent');
                }
                if (!is_string($document['increment_id'] ?? null) || $document['increment_id'] === '') {
                    throw new SyncException('Legacy document has no number.', 'legacy_inconsistent');
                }
                if ($key === 'invoices') {
                    $invoiceIds[(string) $document['entity_id']] = true;
                }
                if ($key === 'creditmemos' && !empty($document['invoice_id']) && !isset($invoiceIds[(string) $document['invoice_id']])) {
                    throw new SyncException('Legacy credit memo references an invoice of another order.', 'legacy_inconsistent');
                }
                $active = $key === 'shipments' || ($key === 'invoices' ? (int) ($document['state'] ?? 0) !== 3 : (int) ($document['state'] ?? 0) === 2);
                if (!$active) {
                    continue;
                }
                if ($key !== 'shipments') {
                    $total += (float) $document['grand_total'];
                }
                foreach ($document['items'] ?? [] as $child) {
                    $orderItem = (string) $child['order_item_id'];
                    if (!isset($items[$orderItem])) {
                        throw new SyncException('Legacy document line references another order.', 'legacy_inconsistent');
                    }
                    $qty[$orderItem] += (float) $child['qty'];
                }
            }
            foreach ($items as $id => $item) {
                if (array_key_exists($qtyField, $item) && !SyncFields::same((float) $item[$qtyField], $qty[$id])) {
                    throw new SyncException(sprintf('Legacy %s do not add up to %s for item %s.', $key, $qtyField, $id), 'legacy_inconsistent');
                }
            }
            $orderTotal = ['invoices' => 'total_invoiced', 'creditmemos' => 'total_refunded'][$key] ?? null;
            if ($orderTotal !== null && array_key_exists($orderTotal, $order) && !SyncFields::same((float) $order[$orderTotal], $total)) {
                throw new SyncException(sprintf('Legacy %s do not add up to %s.', $key, $orderTotal), 'legacy_inconsistent');
            }
        }
    }

    /** @return array<string, int> */
    private function mapItems(array $legacyItems, array $destItems): array
    {
        if (count($legacyItems) !== count($destItems) || $legacyItems === []) {
            throw new SyncException('Order line count differs.', 'item_mismatch');
        }
        $legacyIndex = array_flip(array_map(fn ($i) => (string) $i['item_id'], $legacyItems));
        $destIndex = array_flip(array_map(fn ($i) => (string) $i['item_id'], $destItems));
        $map = [];
        foreach ($legacyItems as $n => $li) {
            $mi = $destItems[$n];
            if ((string) $li['sku'] !== (string) $mi['sku'] || !SyncFields::same($li['qty_ordered'], $mi['qty_ordered'])) {
                throw new SyncException('Order line ' . ($n + 1) . ' differs (sku/qty_ordered).', 'item_mismatch');
            }
            $lp = empty($li['parent_item_id']) ? null : ($legacyIndex[(string) $li['parent_item_id']] ?? -1);
            $mp = empty($mi['parent_item_id']) ? null : ($destIndex[(string) $mi['parent_item_id']] ?? -2);
            if ($lp !== $mp) {
                throw new SyncException('Order line parent structure differs.', 'item_mismatch');
            }
            $map[(string) $li['item_id']] = (int) $mi['item_id'];
        }
        return $map;
    }

    private function assertSameDocument(string $key, array $legacy, array $dest, array $itemMap): void
    {
        foreach (SyncFields::DOCUMENT_CONTENT[$key] as $field) {
            if (array_key_exists($field, $legacy) && !SyncFields::same($legacy[$field], $dest[$field] ?? null)) {
                throw new SyncException(sprintf('%s %s differs in %s.', $key, $legacy['increment_id'], $field), 'document_mismatch');
            }
        }
        $lq = $mq = [];
        foreach ($legacy['items'] ?? [] as $child) {
            $mapped = $itemMap[(string) $child['order_item_id']] ?? 0;
            $lq[$mapped] = ($lq[$mapped] ?? 0) + (float) $child['qty'];
        }
        foreach ($dest['items'] ?? [] as $child) {
            $mq[(int) $child['order_item_id']] = ($mq[(int) $child['order_item_id']] ?? 0) + (float) $child['qty'];
        }
        ksort($lq);
        ksort($mq);
        if (array_keys($lq) !== array_keys($mq) || array_filter(array_map(fn ($a, $b) => !SyncFields::same($a, $b), $lq, $mq))) {
            throw new SyncException(sprintf('%s %s line quantities differ.', $key, $legacy['increment_id']), 'document_mismatch');
        }
    }

    /** @return list<array> legacy comments absent from the destination (destination-only ones are warned about) */
    private function missingComments(array $legacy, array $dest, array &$warnings, string $where, bool $orderLevel = false): array
    {
        $key = fn (array $c) => implode('|', [(string) ($c['created_at'] ?? ''), $orderLevel ? (string) ($c['status'] ?? '') : '',
            trim((string) ($c['comment'] ?? '')), $orderLevel ? (string) ($c['entity_name'] ?? '') : '']);
        $destKeys = [];
        foreach ($dest as $comment) {
            $destKeys[$key($comment)] = ($destKeys[$key($comment)] ?? 0) + 1;
        }
        $missing = [];
        foreach ($legacy as $comment) {
            $k = $key($comment);
            if (($destKeys[$k] ?? 0) > 0) {
                $destKeys[$k]--;
                continue;
            }
            $missing[] = $comment;
        }
        $extra = array_sum($destKeys);
        if ($extra > 0) {
            $warnings[] = sprintf('%d destination-only %s comment(s) kept', $extra, $where);
        }
        return $missing;
    }

    private function trackKey(array $track): string
    {
        return implode('|', [trim((string) ($track['track_number'] ?? '')), (string) ($track['carrier_code'] ?? ''), (string) ($track['title'] ?? '')]);
    }

    private function isCompanyFallback(array $legacy, array $dest): bool
    {
        $legacyCompany = trim((string) ($legacy['company'] ?? ''));
        $fallback = trim(trim((string) ($legacy['firstname'] ?? '')) . ' ' . trim((string) ($legacy['lastname'] ?? '')));
        return $legacyCompany === '' && $fallback !== '' && trim((string) ($dest['company'] ?? '')) === $fallback;
    }
}
