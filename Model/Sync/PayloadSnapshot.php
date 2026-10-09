<?php
declare(strict_types=1);

namespace Venuno\OrderImport\Model\Sync;

use Venuno\OrderImport\Model\Materialisation\MaterialisationException;
use Venuno\OrderImport\Model\Materialisation\OrderDraft;
use Venuno\OrderImport\Model\Materialisation\OrderHistory;

/**
 * Builds a legacy snapshot from a Venuno payload (complete order-history v1) for destinations that have
 * no direct read-only access to the legacy database. The payload passes exactly the importer's
 * validation ({@see OrderHistory::fromDraft}) first. Fields the contract does not carry (e.g. order
 * customer_* fields) are absent and therefore never synced in this mode.
 */
final class PayloadSnapshot
{
    public static function fromDraft(OrderDraft $draft): array
    {
        try {
            $history = OrderHistory::fromDraft($draft);
        } catch (MaterialisationException $e) {
            throw new SyncException('Payload history is invalid: ' . $e->getMessage(), 'payload_invalid', false, $e);
        }
        $h = $history->data;
        $snapshot = [
            'order' => $h['order'],
            'addresses' => ['billing' => self::address($draft->billingAddress), 'shipping' => $draft->isVirtual ? null : self::address($draft->shippingAddress ?? [])],
            'items' => array_values($history->items),
            'payment' => $h['payment'],
            'invoices' => $h['invoices'],
            'shipments' => $h['shipments'],
            'creditmemos' => $h['creditmemos'],
            'status_histories' => $h['status_histories'],
        ];
        usort($snapshot['items'], fn ($a, $b) => (int) $a['item_id'] <=> (int) $b['item_id']);
        return $snapshot;
    }

    private static function address(array $address): ?array
    {
        if ($address === []) {
            return null;
        }
        $row = array_intersect_key($address, array_flip(SyncFields::ADDRESS));
        if (isset($row['street']) && is_array($row['street'])) {
            $row['street'] = implode("\n", array_map('strval', $row['street']));
        }
        return $row;
    }
}
