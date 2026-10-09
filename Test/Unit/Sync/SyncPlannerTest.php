<?php
declare(strict_types=1);

namespace Venuno\OrderImport\Test\Unit\Sync;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Venuno\OrderImport\Model\Sync\SyncException;
use Venuno\OrderImport\Model\Sync\SyncPlan;
use Venuno\OrderImport\Model\Sync\SyncPlanner;
use Venuno\OrderImport\Test\Fixtures\SyncFixture as F;

final class SyncPlannerTest extends TestCase
{
    private function plan(array $legacy, array $dest, $context = null): SyncPlan
    {
        return (new SyncPlanner())->plan($legacy, $dest, $context ?? F::context(), F::LEGACY_ID);
    }

    private function fails(string $reason, array $legacy, array $dest, $context = null): void
    {
        try {
            $this->plan($legacy, $dest, $context);
            self::fail('Expected ' . $reason);
        } catch (SyncException $e) {
            self::assertSame($reason, $e->getReason(), $e->getMessage());
        }
    }

    private static function fields(SyncPlan $plan, string $table): array
    {
        $out = [];
        foreach ($plan->updates as $u) {
            if ($u['table'] === $table) {
                $out[$u['id']] = $u['fields'];
            }
        }
        return $out;
    }

    public function testIdenticalOrderIsNoopDespiteImportArtefacts(): void
    {
        $legacy = F::legacy();
        $plan = $this->plan($legacy, F::dest($legacy));
        self::assertTrue($plan->isEmpty(), json_encode($plan->summary()));
        self::assertSame([], $plan->categories);
    }

    public function testFullyProgressedLegacyOrderAlsoNoopWhenDestinationMatches(): void
    {
        $legacy = F::refund(F::ship(F::invoice(F::approve(F::legacy()))));
        self::assertTrue($this->plan($legacy, F::dest($legacy))->isEmpty());
    }

    public function testApprovalOnLegacyMovesAwaitingApprovalToApproved(): void
    {
        $dest = F::dest(F::legacy());
        $plan = $this->plan(F::approve(F::legacy()), $dest);
        self::assertSame(['state_status'], $plan->categories);
        self::assertSame('new', $plan->targetState);
        self::assertSame('approved', $plan->targetStatus);
        $order = self::fields($plan, 'sales_order')[F::DEST_ID];
        self::assertSame(['from' => 'awaiting_approval', 'to' => 'approved'], $order['status']);
        self::assertArrayNotHasKey('state', $order);
        self::assertSame('2026-10-02 08:00:00', $order['updated_at']['to']);
        self::assertSame([], $plan->inserts);
    }

    public function testMissingStatusAssignmentFailsClosed(): void
    {
        $context = F::context([['awaiting_approval', 'new'], ['approved', 'complete']]);
        $this->fails('status_unmapped', F::approve(F::legacy()), F::dest(F::legacy()), $context);
    }

    public function testAssignmentAddedByDataPatchIsFlaggedNotFailed(): void
    {
        $context = F::context([['awaiting_approval', 'new']], [['approved', 'new']]);
        $plan = $this->plan(F::approve(F::legacy()), F::dest(F::legacy()), $context);
        self::assertContains('needs_status_assignment:new/approved', $plan->categories);
    }

    public function testUnknownStatusFailsClosed(): void
    {
        $legacy = F::legacy();
        $legacy['order']['status'] = 'mystery';
        $this->fails('status_unknown', $legacy, F::dest(F::legacy()));
    }

    public function testLegacyPendingPendingIsNormalisedToNew(): void
    {
        $legacy = F::legacy();
        $legacy['order']['state'] = 'pending';
        $legacy['order']['status'] = 'pending';
        $plan = $this->plan($legacy, F::dest(F::legacy()));
        self::assertSame('new', $plan->targetState);
        self::assertSame(['from' => 'awaiting_approval', 'to' => 'pending'], self::fields($plan, 'sales_order')[F::DEST_ID]['status']);
        self::assertArrayNotHasKey('state', self::fields($plan, 'sales_order')[F::DEST_ID]);
    }

    public function testInvoiceAddedWithOrderAndLineTotalsAndPayment(): void
    {
        $before = F::approve(F::legacy());
        $legacy = F::invoice($before);
        $plan = $this->plan($legacy, F::dest($before));
        self::assertSame(['state_status', 'order_fields', 'item_fields', 'payment_fields', 'invoices_added'], $plan->categories);
        self::assertCount(1, $plan->inserts);
        self::assertSame('invoices', $plan->inserts[0]['kind']);
        self::assertSame('INV-25-1', $plan->inserts[0]['source']['increment_id']);
        $order = self::fields($plan, 'sales_order')[F::DEST_ID];
        self::assertSame('processing', $order['state']['to']);
        self::assertEquals(36.0, $order['total_invoiced']['to']);
        self::assertEquals(0.0, $order['total_due']['to']);
        self::assertEquals(2.0, self::fields($plan, 'sales_order_item')[800]['qty_invoiced']['to']);
        self::assertEquals(36.0, self::fields($plan, 'sales_order_payment')[950]['amount_paid']['to']);
        self::assertSame(['7001' => 800, '7002' => 801], $plan->itemMap);
    }

    public function testShipmentWithTrackingAddedAndTrackForExistingShipment(): void
    {
        $invoiced = F::invoice(F::approve(F::legacy()));
        $shipped = F::ship($invoiced, 2.0, null);
        $plan = $this->plan($shipped, F::dest($invoiced));
        self::assertContains('shipments_added', $plan->categories);
        self::assertSame([], $plan->inserts[0]['source']['tracks']);

        // Tracking number added later in legacy to a shipment the destination already has.
        $tracked = F::ship($invoiced, 2.0, 'TRACK-9');
        $destWithShipment = F::dest($shipped);
        $plan = $this->plan($tracked, $destWithShipment);
        self::assertSame(['tracks_added'], $plan->categories);
        self::assertSame('tracks', $plan->inserts[0]['kind']);
        self::assertSame($destWithShipment['shipments'][0]['entity_id'], $plan->inserts[0]['parent']);
        self::assertSame('TRACK-9', $plan->inserts[0]['source']['track_number']);
    }

    public function testCreditMemoAddedAgainstExistingInvoice(): void
    {
        $invoiced = F::invoice(F::approve(F::legacy()));
        $refunded = F::refund($invoiced);
        $dest = F::dest($invoiced);
        $plan = $this->plan($refunded, $dest);
        self::assertContains('creditmemos_added', $plan->categories);
        self::assertSame(['6100' => $dest['invoices'][0]['entity_id']], $plan->invoiceMap);
        self::assertEquals(12.0, self::fields($plan, 'sales_order')[F::DEST_ID]['total_refunded']['to']);
    }

    public function testInvoiceAndCreditMemoBothNew(): void
    {
        $before = F::approve(F::legacy());
        $plan = $this->plan(F::refund(F::invoice($before)), F::dest($before));
        self::assertSame(['invoices', 'creditmemos'], array_column($plan->inserts, 'kind'));
        self::assertSame([], $plan->invoiceMap, 'invoice is mapped at apply time from the inserted row');
    }

    public function testDocumentLifecycleChangeIsSynced(): void
    {
        $invoiced = F::invoice(F::approve(F::legacy()));
        $dest = F::dest($invoiced);
        $invoiced['invoices'][0]['state'] = 1;
        $invoiced['invoices'][0]['updated_at'] = '2026-10-06 08:00:00';
        $plan = $this->plan($invoiced, $dest);
        self::assertContains('invoices_lifecycle', $plan->categories);
    }

    public static function failClosed(): iterable
    {
        $base = F::invoice(F::approve(F::legacy()));
        yield 'destination-only invoice' => ['destination_only_document', F::approve(F::legacy()), F::dest($base)];
        $other = $base;
        $other['invoices'][0]['grand_total'] = 99.0;
        yield 'invoice content differs' => ['document_mismatch', $base, F::dest($other)];
        $qty = $base;
        $qty['invoices'][0]['items'][0]['qty'] = 1.0;
        $qty['items'][0]['qty_invoiced'] = 1.0;
        yield 'invoice line quantity differs' => ['document_mismatch', $base, F::dest($qty)];
        $shipped = F::ship($base, 2.0, 'T-1');
        $destTrack = F::dest(F::ship($base, 2.0, 'T-2'));
        yield 'destination-only track' => ['destination_only_track', $shipped, $destTrack];
        $sku = F::legacy();
        $sku['items'][1]['sku'] = 'SKU-Z';
        yield 'line sku differs' => ['item_mismatch', F::legacy(), F::dest($sku)];
        $count = F::legacy();
        array_pop($count['items']);
        yield 'line count differs' => ['item_mismatch', F::legacy(), F::dest($count)];
        $parent = F::legacy();
        $parent['items'][1]['parent_item_id'] = 7001;
        yield 'parent structure differs' => ['item_mismatch', $parent, F::dest(F::legacy())];
        $torn = $base;
        $torn['items'][1]['qty_invoiced'] = 0.0;
        yield 'legacy invoices do not add up (torn/mid-change)' => ['legacy_inconsistent', $torn, F::dest(F::legacy())];
        $total = $base;
        $total['order']['total_invoiced'] = 10.0;
        yield 'legacy invoice total differs from order' => ['legacy_inconsistent', $total, F::dest(F::legacy())];
        $currency = F::legacy();
        $currency['order']['order_currency_code'] = 'EUR';
        yield 'currency' => ['currency_mismatch', $currency, F::dest(F::legacy())];
        $identity = F::legacy();
        $identity['order']['increment_id'] = '25000002';
        yield 'identity' => ['identity_mismatch', $identity, F::dest(F::legacy())];
        $method = F::dest(F::legacy());
        $method['payment']['method'] = 'checkmo';
        yield 'payment method' => ['payment_mismatch', F::legacy(), $method];
        $noAddress = F::dest(F::legacy());
        $noAddress['addresses']['shipping'] = null;
        yield 'address on one side only' => ['address_missing', F::legacy(), $noAddress];
    }

    #[DataProvider('failClosed')]
    public function testFailsClosed(string $reason, array $legacy, array $dest): void
    {
        $this->fails($reason, $legacy, $dest);
    }

    public function testDocumentNumberOwnedByAnotherOrderFailsClosed(): void
    {
        $before = F::approve(F::legacy());
        $this->fails('history_number_conflict', F::invoice($before), F::dest($before), F::context(taken: ['INV-25-1']));
    }

    public function testFieldOnlyDriftSyncsCustomerAndAddressFields(): void
    {
        $dest = F::dest(F::legacy());
        $legacy = F::legacy();
        $legacy['order']['customer_email'] = 'new.buyer@example.test';
        $legacy['order']['updated_at'] = '2026-10-08 12:01:23';
        $legacy['addresses']['shipping']['street'] = "2 Low Road";
        $legacy['addresses']['billing']['prefix'] = 'SITE2';
        $plan = $this->plan($legacy, $dest);
        self::assertSame(['customer_fields', 'address_fields'], $plan->categories);
        self::assertSame('new.buyer@example.test', self::fields($plan, 'sales_order')[F::DEST_ID]['customer_email']['to']);
        $addresses = self::fields($plan, 'sales_order_address');
        self::assertSame(['street' => ['from' => "1 High Street\nUnit 2", 'to' => '2 Low Road']], $addresses[902]);
        self::assertSame(['prefix' => ['from' => 'SITE1', 'to' => 'SITE2']], $addresses[901]);
    }

    public function testCompanyFallbackWrittenByImporterIsNotDrift(): void
    {
        $legacy = F::legacy();
        $legacy['addresses']['billing']['company'] = null;
        $dest = F::dest($legacy);
        $dest['addresses']['billing']['company'] = 'Pat Buyer';
        self::assertTrue($this->plan($legacy, $dest)->isEmpty());
        $dest['addresses']['billing']['company'] = 'Someone Else';
        self::assertSame(['address_fields'], $this->plan($legacy, $dest)->categories);
    }

    public function testWhitespaceOnlyDifferencesAreNotDrift(): void
    {
        $legacy = F::legacy();
        $legacy['addresses']['billing']['company'] = 'Acme Ltd ';
        $legacy['order']['customer_email'] = ' buyer@example.test';
        self::assertTrue($this->plan($legacy, F::dest(F::legacy()))->isEmpty());
    }

    public function testUpdatedAtOnlyIsTimestampsOnly(): void
    {
        $dest = F::dest(F::legacy());
        $legacy = F::legacy();
        $legacy['order']['updated_at'] = '2026-10-06 12:00:06';
        $legacy['items'][0]['updated_at'] = '2026-10-06 12:00:06';
        $plan = $this->plan($legacy, $dest);
        self::assertSame(['timestamps_only'], $plan->categories);
    }

    public function testLegacyCommentsAddedVenunoCommentsIgnoredDestinationOnlyWarned(): void
    {
        $legacy = F::legacy();
        $dest = F::dest($legacy);
        $legacy['status_histories'][] = ['entity_id' => 1, 'parent_id' => F::LEGACY_ID, 'created_at' => '2026-10-02 09:00:00', 'status' => 'approved',
            'comment' => 'Approved by manager', 'entity_name' => 'order', 'is_customer_notified' => 1, 'is_visible_on_front' => 0];
        $dest['status_histories'][] = ['entity_id' => 2, 'parent_id' => F::DEST_ID, 'created_at' => '2026-10-06 12:00:05', 'status' => 'canceled',
            'comment' => 'Order cancellation notification email was sent.', 'entity_name' => 'order', 'is_customer_notified' => 1, 'is_visible_on_front' => 0];
        $plan = $this->plan($legacy, $dest);
        self::assertSame(['status_history_added'], $plan->categories);
        self::assertSame('Approved by manager', $plan->inserts[0]['source']['comment']);
        self::assertSame(['1 destination-only order comment(s) kept'], $plan->warnings);
    }

    public function testDestinationAfterSyncPlansNothing(): void
    {
        // Idempotency at plan level: once the destination equals legacy (plus Venuno notes), nothing is planned.
        $legacy = F::refund(F::ship(F::invoice(F::approve(F::legacy()))));
        $dest = F::dest($legacy);
        $dest['status_histories'][] = ['entity_id' => 9, 'parent_id' => F::DEST_ID, 'created_at' => '2026-10-05 08:00:00', 'status' => 'processing',
            'comment' => 'Venuno sync: matched legacy order 25000001', 'entity_name' => 'order', 'is_customer_notified' => 0, 'is_visible_on_front' => 0];
        self::assertTrue($this->plan($legacy, $dest)->isEmpty());
    }

    public function testFingerprintChangesWhenDestinationChanges(): void
    {
        $dest = F::dest(F::legacy());
        $a = SyncPlanner::fingerprint($dest);
        $dest['order']['status'] = 'approved';
        self::assertNotSame($a, SyncPlanner::fingerprint($dest));
    }
}
