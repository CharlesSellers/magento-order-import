<?php
declare(strict_types=1);
namespace Venuno\OrderImport\Test\Fixtures;

final class OrderHistoryFixture
{
    public static function row(float $invoiced = 1, float $shipped = 1, float $refunded = 0): array
    {
        $state = $refunded === 1.0 ? 'closed' : ($invoiced === 1.0 && $shipped === 1.0 ? 'complete' : 'processing');
        $dates = ['created_at'=>'2020-02-29 10:15:00','updated_at'=>'2020-03-01 12:20:00'];
        $currencies = array_fill_keys(['base_currency_code','order_currency_code','store_currency_code','global_currency_code'], 'GBP');
        $order = array_merge($dates, $currencies, [
            'entity_id'=>123,'store_id'=>4,'increment_id'=>'00000123','state'=>$state,'status'=>$state,'is_virtual'=>0,
            'grand_total'=>10,'base_grand_total'=>10,'subtotal'=>10,'base_subtotal'=>10,'shipping_amount'=>0,'base_shipping_amount'=>0,
            'tax_amount'=>0,'base_tax_amount'=>0,'discount_amount'=>0,'base_discount_amount'=>0,
            'total_invoiced'=>$invoiced*10,'base_total_invoiced'=>$invoiced*10,'total_paid'=>$invoiced*10,'base_total_paid'=>$invoiced*10,
            'total_refunded'=>$refunded*10,'base_total_refunded'=>$refunded*10,'total_due'=>(1-$invoiced)*10,'base_total_due'=>(1-$invoiced)*10,
            'total_qty_ordered'=>1,'total_item_count'=>1,'base_to_order_rate'=>1,'base_to_global_rate'=>1,'store_to_order_rate'=>1,'store_to_base_rate'=>1,
        ]);
        $item = array_merge($dates, [
            'item_id'=>101,'order_id'=>123,'product_id'=>9001,'store_id'=>4,'sku'=>'FIXTURE','name'=>'Historical fixture',
            'product_type'=>'simple','is_virtual'=>0,'qty_ordered'=>1,'qty_invoiced'=>$invoiced,'qty_shipped'=>$shipped,
            'qty_refunded'=>$refunded,'qty_canceled'=>0,'price'=>10,'base_price'=>10,'row_total'=>10,'base_row_total'=>10,
            'row_invoiced'=>$invoiced*10,'base_row_invoiced'=>$invoiced*10,'amount_refunded'=>$refunded*10,'base_amount_refunded'=>$refunded*10,
            'tax_amount'=>0,'base_tax_amount'=>0,'discount_amount'=>0,'base_discount_amount'=>0,
        ]);
        $document = static fn(int $id, string $number, float $qty) => array_merge($dates, $currencies, [
            'entity_id'=>$id,'order_id'=>123,'store_id'=>4,'increment_id'=>$number,'state'=>2,
            'billing_address_id'=>8001,'shipping_address_id'=>8002,
            'grand_total'=>$qty*10,'base_grand_total'=>$qty*10,'subtotal'=>$qty*10,'base_subtotal'=>$qty*10,
            'tax_amount'=>0,'base_tax_amount'=>0,'shipping_amount'=>0,'base_shipping_amount'=>0,
            'items'=>[['entity_id'=>$id+1000,'parent_id'=>$id,'order_item_id'=>101,'product_id'=>9001,'sku'=>'FIXTURE','name'=>'Historical fixture','qty'=>$qty,'price'=>10,'base_price'=>10,'row_total'=>$qty*10,'base_row_total'=>$qty*10]],
            'comments'=>[['entity_id'=>$id+2000,'parent_id'=>$id,'created_at'=>$dates['created_at'],'comment'=>'Historical comment','is_customer_notified'=>0,'is_visible_on_front'=>0]],
        ]);
        $shipment = array_merge($dates, [
            'entity_id'=>301,'order_id'=>123,'store_id'=>4,'increment_id'=>'000S123','total_qty'=>$shipped,
            'billing_address_id'=>8001,'shipping_address_id'=>8002,
            'items'=>[['entity_id'=>1301,'parent_id'=>301,'order_item_id'=>101,'product_id'=>9001,'sku'=>'FIXTURE','name'=>'Historical fixture','qty'=>$shipped,'price'=>10]],
            'comments'=>[], 'tracks'=>[['entity_id'=>2301,'parent_id'=>301,'order_id'=>123,'track_number'=>'FIXTURE-TRACK','title'=>'Fixture carrier','carrier_code'=>'custom']+$dates],
        ]);
        $payment = ['method'=>'checkmo','amount_ordered'=>10,'base_amount_ordered'=>10,'amount_paid'=>$invoiced*10,'base_amount_paid'=>$invoiced*10,'amount_refunded'=>$refunded*10,'base_amount_refunded'=>$refunded*10,'po_number'=>'FIXTURE-PO'];
        $history = [
            'version'=>1,'complete'=>true,'order'=>$order,'items'=>[$item],'payment'=>$payment,
            'invoices'=>$invoiced>0?[$document(201,'000I123',$invoiced)]:[],
            'shipments'=>$shipped>0?[$shipment]:[],
            'creditmemos'=>$refunded>0?[$document(401,'000C123',$refunded)+['invoice_id'=>201]]:[],
            'status_histories'=>[['entity_id'=>501,'parent_id'=>123,'created_at'=>$dates['created_at'],'status'=>$state,'comment'=>'Original status history','entity_name'=>'order','is_customer_notified'=>0,'is_visible_on_front'=>0]],
        ];
        $payload = [
            'header'=>array_intersect_key($order,array_flip(['increment_id','created_at','updated_at','state','status','order_currency_code','store_id','is_virtual'])),
            'entity_id'=>123,'increment_id'=>'00000123','store_id'=>4,
            'billing_address'=>['firstname'=>'Historical','lastname'=>'Fixture','company'=>'Local synthetic test','email'=>'fixture@example.test','street'=>['1 Test Road'],'city'=>'London','postcode'=>'SW1A 1AA','country_id'=>'GB','telephone'=>'0000000000'],
            'shipping_method'=>'flatrate_flatrate','line_items'=>[$item],
            'totals'=>['subtotal'=>10,'grand_total'=>10,'shipping_amount'=>0,'tax_amount'=>0,'discount_amount'=>0,'order_currency_code'=>'GBP'],
            'payment'=>$payment,'history'=>$history,
        ];
        return ['source_platform'=>'magento','source_base_url'=>'https://fixture.example.test','source_store_id'=>'4','source_order_entity_id'=>'123','source_order_increment_id'=>'00000123','original_created_at'=>$dates['created_at'],'request_payload'=>json_encode($payload,JSON_THROW_ON_ERROR)];
    }
}
