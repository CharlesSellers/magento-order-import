<?php
declare(strict_types=1);

namespace Venuno\OrderImport\Model\Sync;

/**
 * Reads one order and every related sales record (raw rows) from a Magento 2 sales schema. Used for
 * both sides of a sync: the legacy source (a READ ONLY connection, inside a consistent snapshot) and
 * the destination (inside the per-order write transaction, with the order row locked first).
 *
 * Shape: order, addresses{billing,shipping}, items (by item_id), payment, invoices/shipments/creditmemos
 * (each with items + comments, shipments also tracks), status_histories. Only SELECTs are issued.
 */
class OrderSnapshotReader
{
    private const DOCUMENTS = ['invoices' => 'sales_invoice', 'shipments' => 'sales_shipment', 'creditmemos' => 'sales_creditmemo'];

    /** @return array<string, mixed>|null null when the order does not exist */
    public function read(SqlConnection $db, int $orderId, bool $lockOrder = false): ?array
    {
        if ($orderId < 1) {
            return null;
        }
        $order = $db->fetchRow(
            'SELECT * FROM ' . $db->table('sales_order') . ' WHERE entity_id = ?' . ($lockOrder ? ' FOR UPDATE' : ''),
            [$orderId]
        );
        if ($order === null) {
            return null;
        }
        $snapshot = ['order' => $order, 'addresses' => ['billing' => null, 'shipping' => null]];
        foreach ($db->fetchAll('SELECT * FROM ' . $db->table('sales_order_address') . ' WHERE parent_id = ? ORDER BY entity_id', [$orderId]) as $address) {
            $type = (string) $address['address_type'];
            if (array_key_exists($type, $snapshot['addresses'])) {
                if ($snapshot['addresses'][$type] !== null) {
                    throw new SyncException('Order has more than one ' . $type . ' address.', 'address_ambiguous');
                }
                $snapshot['addresses'][$type] = $address;
            }
        }
        $snapshot['items'] = $db->fetchAll('SELECT * FROM ' . $db->table('sales_order_item') . ' WHERE order_id = ? ORDER BY item_id', [$orderId]);
        $payments = $db->fetchAll('SELECT * FROM ' . $db->table('sales_order_payment') . ' WHERE parent_id = ? ORDER BY entity_id', [$orderId]);
        if (count($payments) > 1) {
            throw new SyncException('Order has more than one payment row.', 'payment_ambiguous');
        }
        $snapshot['payment'] = $payments[0] ?? null;
        foreach (self::DOCUMENTS as $key => $table) {
            $documents = $db->fetchAll('SELECT * FROM ' . $db->table($table) . ' WHERE order_id = ? ORDER BY entity_id', [$orderId]);
            foreach ($documents as &$document) {
                $id = (int) $document['entity_id'];
                $document['items'] = $db->fetchAll('SELECT * FROM ' . $db->table($table . '_item') . ' WHERE parent_id = ? ORDER BY entity_id', [$id]);
                $document['comments'] = $db->fetchAll('SELECT * FROM ' . $db->table($table . '_comment') . ' WHERE parent_id = ? ORDER BY entity_id', [$id]);
                if ($key === 'shipments') {
                    $document['tracks'] = $db->fetchAll('SELECT * FROM ' . $db->table('sales_shipment_track') . ' WHERE parent_id = ? ORDER BY entity_id', [$id]);
                }
            }
            unset($document);
            $snapshot[$key] = $documents;
        }
        $snapshot['status_histories'] = $db->fetchAll(
            'SELECT * FROM ' . $db->table('sales_order_status_history') . ' WHERE parent_id = ? ORDER BY entity_id',
            [$orderId]
        );
        return $snapshot;
    }

    /**
     * Reads the legacy order inside one REPEATABLE READ, READ ONLY transaction so every collection comes
     * from the same point in time (a mid-read change on the live legacy store cannot tear the snapshot).
     */
    public function readConsistent(PdoSqlConnection $legacy, int $orderId): ?array
    {
        $pdo = $legacy->pdo();
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION READ ONLY, WITH CONSISTENT SNAPSHOT');
        try {
            return $this->read($legacy, $orderId);
        } finally {
            $pdo->exec('ROLLBACK');
        }
    }

    /** Fresh (outside any snapshot) read of the legacy order's updated_at, for the mid-sync change check. */
    public function currentUpdatedAt(SqlConnection $legacy, int $orderId): ?string
    {
        $value = $legacy->fetchOne('SELECT updated_at FROM ' . $legacy->table('sales_order') . ' WHERE entity_id = ?', [$orderId]);
        return $value === false || $value === null ? null : (string) $value;
    }
}
