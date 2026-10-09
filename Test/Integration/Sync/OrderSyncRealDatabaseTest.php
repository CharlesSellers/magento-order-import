<?php
declare(strict_types=1);

namespace Venuno\OrderImport\Test\Integration\Sync;

use PHPUnit\Framework\TestCase;
use Venuno\OrderImport\Model\Sync\OrderSnapshotReader;
use Venuno\OrderImport\Model\Sync\OrderSyncEngine;
use Venuno\OrderImport\Model\Sync\PdoSqlConnection;
use Venuno\OrderImport\Model\Sync\SyncApplier;
use Venuno\OrderImport\Model\Sync\SyncContext;
use Venuno\OrderImport\Model\Sync\SyncException;
use Venuno\OrderImport\Model\Sync\SyncPlanner;
use Venuno\OrderImport\Setup\Patch\Data\AddLegacyStatusStateAssignments;
use Venuno\OrderImport\Test\Fixtures\SyncFixture as F;

/**
 * Real MySQL/MariaDB tests of the sync engine against copies of the production legacy and destination
 * sales schemas (DDL + exporter triggers + the scoped orders' rows). Not a full Magento runtime: grid
 * refresh is a recorded callback here (GridPool is exercised only inside Magento).
 *
 *   VENUNO_SYNC_TEST_DSN="mysql:host=127.0.0.1;port=3307" VENUNO_SYNC_TEST_USER=… VENUNO_SYNC_TEST_PASS=…
 *   VENUNO_SYNC_FIXTURES=/path/to/export.json vendor/bin/phpunit --testsuite SyncRealDatabase
 */
final class OrderSyncRealDatabaseTest extends TestCase
{
    private static ?array $env = null;
    private PdoSqlConnection $legacy;
    private PdoSqlConnection $dest;
    private array $fixture;
    private array $gridCalls = [];

    protected function setUp(): void
    {
        self::$env = RealDatabaseHarness::available();
        if (self::$env === null) {
            self::markTestSkipped('Set VENUNO_SYNC_TEST_DSN and VENUNO_SYNC_FIXTURES to run the real-database sync tests.');
        }
        [$this->legacy, $this->dest, $this->fixture] = RealDatabaseHarness::build(self::$env);
        $this->applyDataPatch();
    }

    /** What AddLegacyStatusStateAssignments does on deploy (same SQL semantics, without Magento's setup). */
    private function applyDataPatch(): void
    {
        foreach (AddLegacyStatusStateAssignments::PAIRS as [$status, $state]) {
            $this->dest->execute('INSERT INTO sales_order_status_state (status, state, is_default, visible_on_front)
                SELECT ?, ?, 0, 1 FROM sales_order_status s WHERE s.status = ?
                  AND NOT EXISTS (SELECT 1 FROM sales_order_status_state x WHERE x.status = ? AND x.state = ?)', [$status, $state, $status, $status, $state]);
        }
        $this->dest->execute(file_get_contents(__DIR__ . '/audit-table.sql'));
    }

    private function engine(): OrderSyncEngine
    {
        return new OrderSyncEngine($this->dest, $this->legacy, SyncContext::load($this->dest), function (int $id): void {
            $this->gridCalls[] = $id;
        });
    }

    private function scope(): array
    {
        return array_map(fn ($o) => (int) $o['legacy']['order']['entity_id'], $this->fixture['orders']);
    }

    private function destFingerprints(): array
    {
        $reader = new OrderSnapshotReader();
        $out = [];
        foreach ($this->fixture['orders'] as $o) {
            $id = (int) $o['ledger']['magento_order_id'];
            $out[$id] = SyncPlanner::fingerprint($reader->read($this->dest, $id));
        }
        return $out;
    }

    public function testProductionCopyApplyIsExactIdempotentSideEffectFreeAndReversible(): void
    {
        $legacyBefore = RealDatabaseHarness::checksum($this->legacy->pdo());
        $destBefore = $this->destFingerprints();
        $engine = $this->engine();

        $planned = array_map(fn ($id) => $engine->sync($id, false, 't', 'cli'), $this->scope());
        $failed = array_filter($planned, fn ($o) => $o->status === 'failed');
        self::assertSame([], array_map(fn ($o) => $o->toArray(), $failed));
        $actionable = array_filter($planned, fn ($o) => $o->status === 'planned');
        self::assertNotEmpty($actionable);
        self::assertSame($destBefore, $this->destFingerprints(), 'dry run must not write');

        $applied = [];
        foreach ($actionable as $o) {
            $r = $engine->sync($o->legacyOrderId, true, 'batch-1', 'cli');
            self::assertSame('applied', $r->status, json_encode($r->toArray()));
            $applied[] = $r;
        }
        self::assertCount(count($actionable), $this->gridCalls);

        // Exact: every order now re-plans to nothing (header, state/status, lines, payment, addresses, documents).
        foreach ($this->scope() as $id) {
            $again = $engine->sync($id, true, 'batch-2', 'cli');
            self::assertSame('noop', $again->status, json_encode($again->toArray()));
        }
        self::assertSame(0, (int) $this->dest->fetchOne("SELECT COUNT(*) FROM venuno_order_sync_audit WHERE batch_id = 'batch-2'"), 'idempotent');

        // Side effects: no notifications flagged, no exporter change-log rows, one audit row per order.
        self::assertSame(0, (int) $this->dest->fetchOne('SELECT COUNT(*) FROM sales_order_data_exporter_cl'));
        foreach (['sales_invoice', 'sales_shipment', 'sales_creditmemo'] as $t) {
            self::assertSame(0, (int) $this->dest->fetchOne("SELECT COUNT(*) FROM $t d JOIN venuno_order_sync_audit a ON a.magento_order_id = d.order_id
                WHERE d.send_email <> 0 OR d.email_sent <> 0"), $t);
        }
        self::assertSame(count($actionable), (int) $this->dest->fetchOne("SELECT COUNT(*) FROM venuno_order_sync_audit WHERE status = 'applied'"));
        self::assertSame(0, (int) $this->dest->fetchOne("SELECT COUNT(*) FROM sales_order_status_history h JOIN venuno_order_sync_audit a ON a.magento_order_id = h.parent_id
            WHERE h.comment LIKE 'Venuno sync:%' AND (h.is_customer_notified <> 0 OR h.is_visible_on_front <> 0)"));
        foreach ($this->fixture['orders'] as $o) {
            $l = $o['legacy']['order'];
            $m = $this->dest->fetchRow('SELECT state, status, updated_at, total_invoiced FROM sales_order WHERE entity_id = ?', [$o['ledger']['magento_order_id']]);
            self::assertSame([$l['status'], $l['updated_at']], [$m['status'], $m['updated_at']], $l['increment_id']);
            foreach (['sales_invoice', 'sales_shipment'] as $t) {
                self::assertSame(count($o['legacy'][$t === 'sales_invoice' ? 'invoices' : 'shipments']),
                    (int) $this->dest->fetchOne("SELECT COUNT(*) FROM $t WHERE order_id = ?", [$o['ledger']['magento_order_id']]), $t . ' ' . $l['increment_id']);
            }
        }

        // Reversible: rolling back the batch restores every destination order exactly.
        foreach ($engine->auditIdsForBatch('batch-1') as $auditId) {
            $engine->rollback($auditId);
        }
        self::assertSame($destBefore, $this->destFingerprints());
        self::assertSame(0, (int) $this->dest->fetchOne('SELECT COUNT(*) FROM sales_order_data_exporter_cl'));

        // Legacy was never written (its session is READ ONLY as well).
        self::assertSame($legacyBefore, RealDatabaseHarness::checksum($this->legacy->pdo()));
    }

    public function testDestinationChangedAfterPlanningIsRefusedWithoutWrites(): void
    {
        $id = $this->firstActionable();
        $plan = $this->planFor($id);
        $this->dest->execute("UPDATE sales_order SET customer_note = 'edited on destination' WHERE entity_id = ?", [$plan->magentoOrderId]);
        $before = $this->destFingerprints();
        $this->expectApplyFailure('destination_changed_during_sync', $plan, $id, fn () => $plan->legacyUpdatedAt);
        self::assertSame($before, $this->destFingerprints());
    }

    public function testLegacyChangedDuringSyncRollsBack(): void
    {
        $id = $this->firstActionable();
        $plan = $this->planFor($id);
        $before = $this->destFingerprints();
        $this->expectApplyFailure('legacy_changed_during_sync', $plan, $id, fn () => '2099-01-01 00:00:00');
        self::assertSame($before, $this->destFingerprints());
        self::assertSame(0, (int) $this->dest->fetchOne('SELECT COUNT(*) FROM sales_order_data_exporter_cl'));
        self::assertSame(0, (int) $this->dest->fetchOne('SELECT COUNT(*) FROM venuno_order_sync_audit'));
    }

    public function testRollbackRefusesWhenOrderChangedSinceSync(): void
    {
        $id = $this->firstActionable();
        $engine = $this->engine();
        $r = $engine->sync($id, true, 'b', 'cli');
        self::assertSame('applied', $r->status);
        $this->dest->execute("UPDATE sales_order SET status = 'holded', state = 'holded' WHERE entity_id = ?", [$r->plan->magentoOrderId]);
        try {
            $engine->rollback($r->auditId);
            self::fail('rollback should refuse');
        } catch (SyncException $e) {
            self::assertSame('changed_since_sync', $e->getReason());
        }
        self::assertSame('applied', $this->dest->fetchOne('SELECT status FROM venuno_order_sync_audit WHERE audit_id = ?', [$r->auditId]));
    }

    /** Tracking on a new shipment, a track added to an existing shipment, and a credit memo — on the real schema. */
    public function testSyntheticShipmentTrackingAndCreditMemoOnRealSchema(): void
    {
        $writableLegacy = new PdoSqlConnection(RealDatabaseHarness::connect(self::$env, RealDatabaseHarness::LEGACY_DB));
        $invoiced = F::invoice(F::approve(F::legacy()));
        $destBefore = F::dest(F::legacy());
        RealDatabaseHarness::loadSnapshot($this->dest->pdo(), $destBefore);
        $this->dest->insert('venuno_order_import', ['replay_key' => 'magento:synthetic', 'source_order_entity_id' => (string) F::LEGACY_ID,
            'import_status' => 'imported', 'magento_order_id' => F::DEST_ID, 'source_base_url' => 'https://legacy.example.test']);
        $final = F::refund(F::ship($invoiced, 2.0, 'TRACK-1'));
        RealDatabaseHarness::loadSnapshot($writableLegacy->pdo(), $final);
        $this->dest->execute('DELETE FROM sales_order_data_exporter_cl');

        $engine = $this->engine();
        $r = $engine->sync(F::LEGACY_ID, true, 'synthetic', 'cli');
        self::assertSame('applied', $r->status, json_encode($r->toArray()));
        self::assertEqualsCanonicalizing(['state_status', 'order_fields', 'item_fields', 'payment_fields', 'invoices_added', 'shipments_added', 'creditmemos_added'], $r->plan->categories);
        $invoiceId = (int) $this->dest->fetchOne("SELECT entity_id FROM sales_invoice WHERE increment_id = 'INV-25-1' AND order_id = ?", [F::DEST_ID]);
        self::assertSame($invoiceId, (int) $this->dest->fetchOne("SELECT invoice_id FROM sales_creditmemo WHERE increment_id = 'CM-25-1'"));
        self::assertSame('TRACK-1', $this->dest->fetchOne('SELECT track_number FROM sales_shipment_track WHERE order_id = ?', [F::DEST_ID]));
        self::assertSame([800, 801], array_map('intval', array_column($this->dest->fetchAll('SELECT order_item_id FROM sales_invoice_item i JOIN sales_invoice v ON v.entity_id = i.parent_id WHERE v.order_id = ? ORDER BY order_item_id', [F::DEST_ID]), 'order_item_id')));
        self::assertSame(0, (int) $this->dest->fetchOne('SELECT COUNT(*) FROM sales_order_data_exporter_cl'));
        self::assertSame('noop', $engine->sync(F::LEGACY_ID, true, 'again', 'cli')->status);

        // Later in legacy: a second tracking number on the existing shipment.
        $shipmentId = (int) $final['shipments'][0]['entity_id'];
        $writableLegacy->insert('sales_shipment_track', ['parent_id' => $shipmentId, 'order_id' => F::LEGACY_ID, 'track_number' => 'TRACK-2',
            'title' => 'DPD', 'carrier_code' => 'custom']);
        $r2 = $engine->sync(F::LEGACY_ID, true, 'synthetic-2', 'cli');
        self::assertSame(['tracks_added'], $r2->plan->categories);
        self::assertSame(2, (int) $this->dest->fetchOne('SELECT COUNT(*) FROM sales_shipment_track WHERE order_id = ?', [F::DEST_ID]));

        $engine->rollback($r2->auditId);
        $engine->rollback($r->auditId);
        $after = (new OrderSnapshotReader())->read($this->dest, F::DEST_ID);
        self::assertSame([[], [], []], [$after['invoices'], $after['shipments'], $after['creditmemos']]);
        self::assertSame('awaiting_approval', $after['order']['status']);
        self::assertCount(1, $after['status_histories'], 'only the original import note remains');
        self::assertSame(0, (int) $this->dest->fetchOne('SELECT COUNT(*) FROM sales_shipment_track WHERE order_id = ?', [F::DEST_ID]));
    }

    private function firstActionable(): int
    {
        foreach ($this->scope() as $id) {
            if ($this->engine()->sync($id, false, 'x', 'cli')->status === 'planned') {
                return $id;
            }
        }
        self::fail('no actionable order in fixture');
    }

    private function planFor(int $id)
    {
        return $this->engine()->sync($id, false, 'x', 'cli')->plan;
    }

    private function expectApplyFailure(string $reason, $plan, int $id, \Closure $legacyUpdatedAt): void
    {
        $reader = new OrderSnapshotReader();
        $legacySnapshot = $reader->readConsistent($this->legacy, $id);
        try {
            (new SyncApplier($reader, new SyncPlanner()))->apply($plan, $legacySnapshot, $this->dest, SyncContext::load($this->dest), $legacyUpdatedAt,
                fn () => null, 'fail', 'cli');
            self::fail('expected ' . $reason);
        } catch (SyncException $e) {
            self::assertSame($reason, $e->getReason(), $e->getMessage());
        }
    }
}
