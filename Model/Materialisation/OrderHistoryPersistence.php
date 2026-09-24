<?php
declare(strict_types=1);

namespace Venuno\OrderImport\Model\Materialisation;

use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\ResourceModel\GridPool;

/** Archive already-existing source sales records. Never call register/pay/capture/refund/ship services. */
class OrderHistoryPersistence
{
    public function __construct(private readonly ResourceConnection $resource, private readonly GridPool $grids) {}

    /** @param array<string, \Magento\Sales\Model\Order\Item> $nativeItems */
    public function persist(OrderInterface $order, OrderHistory $history, array $nativeItems): void
    {
        $db = $this->resource->getConnection();
        if ($db->getTransactionLevel() < 1 || !(int) $order->getEntityId()) throw new \RuntimeException('History archive requires the order transaction.');
        $orderId = (int) $order->getEntityId();
        $billingId = (int) $order->getBillingAddressId();
        $shippingId = (int) $order->getShippingAddressId();
        foreach ($history->items as $sourceId=>$row) {
            $native = $nativeItems[$sourceId] ?? null;
            if (!$native || !(int) $native->getId()) throw new \RuntimeException('Native order item mapping is incomplete.');
            $dates = array_intersect_key($row, array_flip(['created_at','updated_at']));
            if ($dates) $db->update($this->resource->getTableName('sales_order_item'), $dates, ['item_id = ?'=>(int)$native->getId(), 'order_id = ?'=>$orderId]);
        }
        $invoiceIds = [];
        foreach (['invoices'=>'invoice','shipments'=>'shipment','creditmemos'=>'creditmemo'] as $key=>$kind) {
            foreach ($history->data[$key] as $document) {
                $table = 'sales_' . $kind;
                $existing = $db->fetchOne($db->select()->from($this->resource->getTableName($table), ['entity_id'])
                    ->where('store_id = ?', (int)$order->getStoreId())->where('increment_id = ?', $document['increment_id'])->limit(1));
                if ($existing !== false) throw new MaterialisationException('A source sales-document number already exists in this destination store.', 'history_number_conflict', false);
                $mapped = ['store_id'=>(int)$order->getStoreId(), 'order_id'=>$orderId, 'billing_address_id'=>$billingId, 'shipping_address_id'=>$shippingId ?: null, 'send_email'=>0, 'email_sent'=>0];
                if ($kind === 'shipment') $mapped['customer_id'] = $order->getCustomerId();
                if ($kind === 'creditmemo') $mapped['invoice_id'] = !empty($document['invoice_id']) ? ($invoiceIds[(string)$document['invoice_id']] ?? null) : null;
                $id = $this->insert($table, $document, $mapped, ['entity_id','items','comments','tracks','customer_id']);
                if ($kind === 'invoice') $invoiceIds[(string)$document['entity_id']] = $id;
                foreach ($document['items'] as $item) {
                    $native = $nativeItems[(string)$item['order_item_id']];
                    $this->insert($table . '_item', $item, ['parent_id'=>$id, 'order_item_id'=>(int)$native->getId(), 'product_id'=>(int)$native->getProductId()], ['entity_id']);
                }
                foreach ($document['comments'] as $comment) {
                    $this->insert($table . '_comment', $comment, ['parent_id'=>$id], ['entity_id','status','entity_name']);
                }
                if ($kind === 'shipment') foreach ($document['tracks'] as $track) {
                    $this->insert('sales_shipment_track', $track, ['parent_id'=>$id, 'order_id'=>$orderId], ['entity_id']);
                }
            }
        }
        foreach ($history->data['status_histories'] as $comment) {
            $this->insert('sales_order_status_history', $comment, ['parent_id'=>$orderId], ['entity_id']);
        }
        $this->grids->refreshByOrderId($orderId);
    }

    private function insert(string $table, array $source, array $mapped, array $remove): int
    {
        $db = $this->resource->getConnection();
        $table = $this->resource->getTableName($table);
        $data = array_replace(array_diff_key($source, array_flip($remove)), $mapped);
        $columns = $db->describeTable($table);
        if (array_diff_key($data, $columns)) throw new MaterialisationException('Destination sales schema cannot store the complete history record.', 'history_schema_mismatch', false);
        $db->insert($table, $data);
        return (int) $db->lastInsertId($table);
    }
}
