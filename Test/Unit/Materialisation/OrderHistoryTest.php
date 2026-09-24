<?php
declare(strict_types=1);
namespace Venuno\OrderImport\Test\Unit\Materialisation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Venuno\OrderImport\Model\Materialisation\{OrderDraftBuilder,OrderHistory,MaterialisationException};
use Venuno\OrderImport\Test\Fixtures\OrderHistoryFixture;

final class OrderHistoryTest extends TestCase
{
    #[DataProvider('validQuantities')]
    public function testCompleteAndPartialHistory(float $invoiced,float $shipped,float $refunded): void
    {
        $h=OrderHistory::fromDraft((new OrderDraftBuilder())->fromImportRow(OrderHistoryFixture::row($invoiced,$shipped,$refunded)));
        self::assertSame($invoiced,(float)$h->items[101]['qty_invoiced']);
        self::assertSame($shipped,(float)$h->items[101]['qty_shipped']);
        self::assertSame($refunded,(float)$h->items[101]['qty_refunded']);
    }
    public static function validQuantities(): iterable
    {
        yield [1.0,1.0,0.0]; yield [0.5,0.25,0.1]; yield [1.0,1.0,1.0]; yield [0.0,0.0,0.0];
    }
    #[DataProvider('invalidChanges')]
    public function testIncompleteOrConflictingHistoryIsTerminal(array $path,mixed $value): void
    {
        $row=OrderHistoryFixture::row(); $payload=json_decode($row['request_payload'],true);
        $target=&$payload; foreach($path as $key) $target=&$target[$key]; $target=$value; unset($target);
        $row['request_payload']=json_encode($payload,JSON_THROW_ON_ERROR);
        try {OrderHistory::fromDraft((new OrderDraftBuilder())->fromImportRow($row)); self::fail('Expected history validation failure');}
        catch(MaterialisationException $e) { self::assertSame('source_history_invalid',$e->getReason()); self::assertFalse($e->isRetryable()); }
    }
    public static function invalidChanges(): iterable
    {
        yield [['history','version'],2]; yield [['history','complete'],false];
        yield [['history','invoices'],[]]; yield [['history','shipments'],[]];
        yield [['history','order','entity_id'],999]; yield [['history','order','grand_total'],11];
        yield [['history','order','total_invoiced'],20]; yield [['history','order','base_currency_code'],null];
        yield [['history','payment','cc_number_enc'],'not-allowed'];
        yield [['history','items',0,'parent_item_id'],101]; yield [['history','items',0,'parent_item_id'],999];
        yield [['history','items',0,'qty_invoiced'],2]; yield [['history','items',0,'qty_canceled'],-1];
        yield [['history','invoices',0,'items',0,'order_item_id'],999];
        yield [['history','invoices',0,'items',0,'qty'],0.5];
        yield [['history','invoices',0,'store_id'],999]; yield [['history','invoices',0,'comments',0,'parent_id'],999];
        yield [['history','shipments',0,'tracks',0,'order_id'],999];
        yield [['history','status_histories',0,'parent_id'],999];
        yield [['history','invoices',0,'created_at'],'2020-02-30 00:00:00'];
    }
}
