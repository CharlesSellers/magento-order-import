<?php
declare(strict_types=1);
namespace Venuno\OrderImport\Test\Unit;
use PHPUnit\Framework\TestCase;
use Venuno\OrderImport\Model\SourceFinancialsReader;

final class SourceFinancialsReaderTest extends TestCase
{
    public function testTimestampSessionMatchesMagentoUtcBeforeReadOnlySnapshot(): void
    {
        $db=$this->createMock(\PDO::class);
        $commands=[];
        $db->method('exec')->willReturnCallback(static function($sql)use(&$commands){$commands[]=$sql;return 0;});
        $query=$this->createMock(\PDOStatement::class);
        $query->method('fetch')->willReturn(['entity_id'=>1]);
        $query->method('fetchAll')->willReturn([['item_id'=>1]]);
        $db->method('prepare')->willReturn($query);
        $db->expects(self::once())->method('rollBack');
        (new SourceFinancialsReader())->read($db,1);
        self::assertContains("SET SESSION time_zone = '+00:00'",$commands);
        self::assertLessThan(array_search('START TRANSACTION READ ONLY',$commands,true),array_search("SET SESSION time_zone = '+00:00'",$commands,true));
        self::assertSame([],array_values(array_filter($commands,static fn($sql)=>str_contains($sql,'GLOBAL'))));
    }
    public function testReadOnlyTransactionAndBoundQueriesAlwaysRollback(): void
    {
        $db=$this->createMock(\PDO::class);
        $commands=[];$db->method('exec')->willReturnCallback(static function($sql)use(&$commands){$commands[]=$sql;return 0;});
        $order=$this->createMock(\PDOStatement::class);$order->expects(self::once())->method('execute')->with([234930]);$order->method('fetch')->willReturn(['entity_id'=>234930]);
        $items=$this->createMock(\PDOStatement::class);$items->expects(self::once())->method('execute')->with([234930]);$items->method('fetchAll')->willReturn([['item_id'=>1,'row_total'=>'52.9800','row_total_incl_tax'=>'63.5700']]);
        $db->method('prepare')->willReturnCallback(static function($sql)use($order,$items){self::assertStringStartsWith('SELECT ',$sql);self::assertStringContainsString('=?',$sql);self::assertStringNotContainsString('customer_email',$sql);return str_contains($sql,'FROM sales_order_item')?$items:$order;});
        $db->expects(self::once())->method('rollBack');
        $result=(new SourceFinancialsReader())->read($db,234930);
        self::assertContains('START TRANSACTION READ ONLY',$commands);
        self::assertSame('63.5700',$result['items'][0]['row_total_incl_tax']);
    }
    public function testMissingOrderStillRollsBack(): void
    {
        $db=$this->createMock(\PDO::class);$q=$this->createMock(\PDOStatement::class);$q->method('fetch')->willReturn(false);$db->method('prepare')->willReturn($q);$db->expects(self::once())->method('rollBack');
        $this->expectException(\RuntimeException::class);(new SourceFinancialsReader())->read($db,1);
    }
    public function testInvalidIdNeverTouchesDatabase(): void
    {
        $db=$this->createMock(\PDO::class);$db->expects(self::never())->method('exec');$this->expectException(\InvalidArgumentException::class);(new SourceFinancialsReader())->read($db,0);
    }
}
