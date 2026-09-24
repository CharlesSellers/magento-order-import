<?php
declare(strict_types=1);

namespace Venuno\OrderImport\Model\Materialisation;

use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\ResourceModel\Grid;

/** Runs inside the SAME transaction as order creation and the replay ledger. Never links an existing order. */
class SourceMetadataPersistence
{
    public function __construct(private readonly ResourceConnection $resource, private readonly Grid $orderGrid)
    {
    }

    public function assertAvailable(OrderDraft $draft, SourceOrderMetadata $metadata): void
    {
        $db = $this->resource->getConnection();
        if ($db->getTransactionLevel() < 1) {
            throw new \RuntimeException('Source metadata requires the materialisation transaction before creating an order.');
        }
        $mapped = $db->fetchOne($db->select()->from($this->resource->getTableName('sales_order_status_state'), ['status'])
            ->where('state = ?', $metadata->state)->where('status = ?', $metadata->status)->limit(1));
        if ($mapped === false) {
            throw new MaterialisationException('Source status is not assigned to its state on the destination; no fallback status was used.',
                MaterialisationException::REASON_SOURCE_STATUS_UNMAPPED, false);
        }
        $existing = $db->fetchOne($db->select()->from($this->resource->getTableName('sales_order'), ['entity_id'])
            ->where('store_id = ?', $draft->storeId)->where('increment_id = ?', $metadata->incrementId)->limit(1));
        if ($existing !== false) {
            throw new MaterialisationException('An order with this number already exists in the destination store; reconcile its replay identity before retrying.',
                MaterialisationException::REASON_ORDER_NUMBER_CONFLICT, false);
        }
        // Magento also enforces UNIQUE(increment_id, store_id), closing the concurrent preflight race.
    }

    public function finish(OrderInterface $order, OrderDraft $draft, SourceOrderMetadata $metadata): void
    {
        $db = $this->resource->getConnection();
        $table = $this->resource->getTableName('sales_order');
        $id = (int) $order->getEntityId();
        if ($id <= 0 || $db->getTransactionLevel() < 1) {
            throw new \RuntimeException('Source metadata requires a saved order inside the materialisation transaction.');
        }
        $row = $db->fetchRow($db->select()->from($table, ['store_id', 'increment_id', 'state', 'status'])
            ->where('entity_id = ?', $id));
        if (!$row || (int) $row['store_id'] !== $draft->storeId || $row['increment_id'] !== $metadata->incrementId
            || $row['state'] !== $metadata->state || $row['status'] !== $metadata->status) {
            throw new MaterialisationException('Magento changed the imported identity or state/status during save; order creation has been rejected.',
                MaterialisationException::REASON_SOURCE_METADATA_INVALID, false);
        }
        // EntityAbstract removes updated_at from ORM saves. Restore BOTH dates narrowly, before the
        // ledger commits, without another save/observer cycle or a global timestamp plugin.
        $db->update($table, ['created_at' => $metadata->createdAt, 'updated_at' => $metadata->updatedAt], ['entity_id = ?' => $id]);
        $dates = $db->fetchRow($db->select()->from($table, ['created_at', 'updated_at'])->where('entity_id = ?', $id));
        if (!$dates || $dates['created_at'] !== $metadata->createdAt || $dates['updated_at'] !== $metadata->updatedAt) {
            throw new \RuntimeException('Imported source dates failed read-back verification.');
        }
        $order->setCreatedAt($metadata->createdAt);
        $order->setUpdatedAt($metadata->updatedAt);
        // Explicit scoped refresh also covers async grids whose date watermark is newer than this order.
        $this->orderGrid->refresh($id);
    }
}
