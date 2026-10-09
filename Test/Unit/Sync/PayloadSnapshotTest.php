<?php
declare(strict_types=1);

namespace Venuno\OrderImport\Test\Unit\Sync;

use PHPUnit\Framework\TestCase;
use Venuno\OrderImport\Console\Command\OrderSyncCommand;
use Venuno\OrderImport\Model\Materialisation\OrderDraftBuilder;
use Venuno\OrderImport\Model\Sync\PayloadSnapshot;
use Venuno\OrderImport\Model\Sync\SyncContext;
use Venuno\OrderImport\Model\Sync\SyncException;
use Venuno\OrderImport\Model\Sync\SyncFields;
use Venuno\OrderImport\Model\Sync\SyncPlanner;
use Venuno\OrderImport\Test\Fixtures\OrderHistoryFixture;

final class PayloadSnapshotTest extends TestCase
{
    private static function snapshot(float $invoiced = 1, float $shipped = 1, float $refunded = 0): array
    {
        return PayloadSnapshot::fromDraft((new OrderDraftBuilder())->fromImportRow(OrderHistoryFixture::row($invoiced, $shipped, $refunded)));
    }

    public function testValidatedPayloadBecomesLegacySnapshot(): void
    {
        $s = self::snapshot();
        self::assertSame('00000123', $s['order']['increment_id']);
        self::assertSame('1 Test Road', $s['addresses']['billing']['street']);
        self::assertSame('Local synthetic test', $s['addresses']['billing']['company']);
        self::assertSame('FIXTURE-TRACK', $s['shipments'][0]['tracks'][0]['track_number']);
        self::assertArrayNotHasKey('customer_email', $s['order'], 'not in the contract, so never synced in payload mode');
    }

    public function testInvalidPayloadIsRefused(): void
    {
        $row = OrderHistoryFixture::row();
        $payload = json_decode($row['request_payload'], true);
        $payload['history']['complete'] = false;
        $row['request_payload'] = json_encode($payload);
        $this->expectException(SyncException::class);
        PayloadSnapshot::fromDraft((new OrderDraftBuilder())->fromImportRow($row));
    }

    public function testPayloadModePlansMissingShipmentAndRefund(): void
    {
        $legacy = self::snapshot(1, 1, 1);
        $before = self::snapshot(1, 0, 0);
        // Destination as imported earlier (ids differ; fields absent from the payload are not compared).
        $dest = $before;
        $dest['order']['entity_id'] = 900;
        foreach ($dest['items'] as &$item) {
            $item['item_id'] = 9101;
        }
        unset($item);
        foreach ($dest['invoices'] as &$invoice) {
            $invoice['entity_id'] = 9201;
            $invoice['items'][0]['order_item_id'] = 9101;
        }
        unset($invoice);
        $dest['addresses']['billing']['entity_id'] = 1;
        $dest['addresses']['shipping'] = null;
        $legacy['addresses']['shipping'] = null;
        $dest['payment']['entity_id'] = 2;
        $context = new SyncContext(['closed' => ['closed' => true], 'processing' => ['processing' => true]], ['closed' => true, 'processing' => true], fn () => false);
        $plan = (new SyncPlanner())->plan($legacy, $dest, $context, 123);
        self::assertContains('shipments_added', $plan->categories);
        self::assertContains('creditmemos_added', $plan->categories);
        self::assertSame(['9101'], array_map('strval', array_values($plan->itemMap)));
    }

    public function testSameAndVenunoComment(): void
    {
        self::assertTrue(SyncFields::same('10.0000', 10));
        self::assertTrue(SyncFields::same('', null));
        self::assertFalse(SyncFields::same('0', null));
        self::assertFalse(SyncFields::same('a', 'A'));
        self::assertTrue(SyncFields::isVenunoComment(['comment' => 'Imported from magento order 1 (entity_id 2) via Venuno.']));
        self::assertTrue(SyncFields::isVenunoComment(['comment' => 'Venuno reconciliation 2026-10-09: x']));
        self::assertFalse(SyncFields::isVenunoComment(['comment' => 'Imported from somewhere']));
    }

    public function testOrdersFileParsing(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'ids');
        file_put_contents($file, "# frozen\n235775\n235877 # dup below\n\n235775\n");
        self::assertSame([235775, 235877], OrderSyncCommand::readIds($file));
        file_put_contents($file, "1000087031-x\n");
        $this->expectException(\InvalidArgumentException::class);
        OrderSyncCommand::readIds($file);
    }
}
