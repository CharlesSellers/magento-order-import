<?php
declare(strict_types=1);
namespace Venuno\OrderImport\Model;

/** Only fixed SELECTs with bound identifiers; financial/identity fields, no customer/payment data. */
class SourceFinancialsReader
{
    public function read(\PDO $db, int $orderId): array
    {
        if ($orderId < 1) { throw new \InvalidArgumentException('Invalid order identifier.'); }
        // Match Magento's PDO adapter: TIMESTAMP reads must not inherit the DB server's local/DST zone.
        $db->exec("SET SESSION time_zone = '+00:00'");
        $db->exec('SET SESSION MAX_EXECUTION_TIME=5000');
        $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $db->exec('START TRANSACTION READ ONLY');
        try {
            $q=$db->prepare('SELECT entity_id,increment_id,store_id,updated_at,order_currency_code,base_currency_code,subtotal,base_subtotal,grand_total,base_grand_total,discount_amount,base_discount_amount FROM sales_order WHERE entity_id=?');
            $q->execute([$orderId]);$order=$q->fetch(\PDO::FETCH_ASSOC);
            if (!$order) { throw new \RuntimeException('Source order not found.'); }
            $q=$db->prepare('SELECT item_id,order_id,sku,qty_ordered,price,base_price,discount_amount,base_discount_amount,tax_amount,base_tax_amount,row_total,base_row_total,row_total_incl_tax,base_row_total_incl_tax FROM sales_order_item WHERE order_id=? ORDER BY item_id LIMIT 1001');
            $q->execute([$orderId]);$items=$q->fetchAll(\PDO::FETCH_ASSOC);
            if (count($items)===0 || count($items)>1000) { throw new \RuntimeException('Invalid source item count.'); }
            return ['version'=>1,'order'=>$order,'items'=>$items];
        } finally { $db->rollBack(); }
    }
}
