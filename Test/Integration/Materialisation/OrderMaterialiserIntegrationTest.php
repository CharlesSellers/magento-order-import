<?php
/**
 * Copyright © Venuno. All rights reserved.
 */

declare(strict_types=1);

namespace Venuno\OrderImport\Test\Integration\Materialisation;

use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;
use Venuno\OrderImport\Model\Materialisation\MaterialisationException;
use Venuno\OrderImport\Model\Materialisation\OrderMaterialiser;
use Venuno\OrderImport\Model\OrderImportRepository;

/**
 * End-to-end materialisation against a REAL Magento — this is the live validation module ADR-0003/0005
 * require before native order creation can be trusted. It runs only under Magento's integration test
 * framework (`dev/tests/integration`); see Test/Integration/README.md. It is intentionally NOT part of
 * the Magento-free `Unit` suite.
 *
 * @magentoDbIsolation enabled
 * @magentoAppIsolation enabled
 */
final class OrderMaterialiserIntegrationTest extends TestCase
{
    private const TABLE = 'venuno_order_import';

    private \Magento\Framework\ObjectManagerInterface $objectManager;
    private OrderImportRepository $repository;
    private OrderMaterialiser $materialiser;
    private OrderRepositoryInterface $orders;
    private ResourceConnection $resource;

    protected function setUp(): void
    {
        $this->objectManager = Bootstrap::getObjectManager();
        $this->repository = $this->objectManager->get(OrderImportRepository::class);
        $this->materialiser = $this->objectManager->get(OrderMaterialiser::class);
        $this->orders = $this->objectManager->get(OrderRepositoryInterface::class);
        $this->resource = $this->objectManager->get(ResourceConnection::class);
    }

    /**
     * @magentoDataFixture Magento/Catalog/_files/product_simple.php
     */
    public function testMaterialisesAStagedRowIntoANativeOrder(): void
    {
        $replayKey = 'magento:int-' . uniqid();
        $this->stage($replayKey, $this->order('simple'));

        $result = $this->materialiser->materialise($replayKey);

        self::assertTrue($result->created);
        self::assertGreaterThan(0, $result->magentoOrderId);

        $order = $this->orders->get($result->magentoOrderId);
        self::assertSame('100000123', $order->getExtOrderId(), 'external reference preserved');
        self::assertEqualsWithDelta(29.0, (float) $order->getGrandTotal(), 0.001, 'source totals preserved');
        self::assertCount(1, $order->getAllVisibleItems());

        $row = $this->repository->findByReplayKey($replayKey);
        self::assertSame('imported', $row['import_status']);
        self::assertSame($result->magentoOrderId, (int) $row['magento_order_id']);
        self::assertNotEmpty($row['materialised_at']);
    }

    /**
     * @magentoDataFixture Magento/Catalog/_files/product_simple.php
     */
    public function testIsIdempotentOnReplay(): void
    {
        $replayKey = 'magento:int-' . uniqid();
        $this->stage($replayKey, $this->order('simple'));

        $first = $this->materialiser->materialise($replayKey);
        $second = $this->materialiser->materialise($replayKey);

        self::assertTrue($first->created);
        self::assertFalse($second->created, 'a replay creates no second order');
        self::assertSame($first->magentoOrderId, $second->magentoOrderId);
    }

    public function testUnknownSkuFailsTerminallyAndIsReplayable(): void
    {
        $replayKey = 'magento:int-' . uniqid();
        $this->stage($replayKey, $this->order('does-not-exist-sku'));

        try {
            $this->materialiser->materialise($replayKey);
            self::fail('expected a terminal MaterialisationException');
        } catch (MaterialisationException $e) {
            self::assertSame(MaterialisationException::REASON_UNKNOWN_SKU, $e->getReason());
            self::assertFalse($e->isRetryable());
        }

        $row = $this->repository->findByReplayKey($replayKey);
        self::assertSame('failed', $row['import_status'], 'a failed attempt is recorded for replay');
        self::assertSame(0, (int) $row['magento_order_id'], 'no orphan order on failure (transactional rollback)');
        self::assertNotEmpty($row['error_message']);
    }

    /**
     * @param array<string, mixed> $order
     */
    private function stage(string $replayKey, array $order): void
    {
        $this->repository->insert([
            'replay_key' => $replayKey,
            'payload_hash' => 'int',
            'source_platform' => 'magento',
            'source_base_url' => 'https://source.example.com',
            'source_store_id' => '1',
            'source_order_entity_id' => '71951',
            'source_order_increment_id' => '100000123',
            'source_order_display_number' => '100000123',
            'import_status' => 'pending',
            'request_payload' => json_encode($order),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function order(string $sku): array
    {
        return [
            'header' => ['increment_id' => '100000123', 'order_currency_code' => 'USD', 'is_virtual' => 0, 'store_id' => 1],
            'billing_address' => [
                'firstname' => 'Ada', 'lastname' => 'Lovelace', 'street' => ['1 High St'],
                'city' => 'London', 'postcode' => 'E1', 'country_id' => 'GB', 'telephone' => '01',
                'email' => 'ada@example.com',
            ],
            'shipping_address' => [
                'firstname' => 'Ada', 'lastname' => 'Lovelace', 'street' => ['1 High St'],
                'city' => 'London', 'postcode' => 'E1', 'country_id' => 'GB', 'telephone' => '01',
            ],
            'shipping_method' => 'flatrate_flatrate',
            'shipping_description' => 'Flat Rate',
            'line_items' => [[
                'sku' => $sku, 'name' => 'Item', 'qty_ordered' => 2, 'price' => 10.0,
                'row_total' => 20.0, 'tax_amount' => 4.0, 'discount_amount' => 0.0,
            ]],
            'totals' => [
                'subtotal' => 20.0, 'shipping_amount' => 5.0, 'discount_amount' => 0.0,
                'tax_amount' => 4.0, 'grand_total' => 29.0, 'order_currency_code' => 'USD',
            ],
            'payment' => ['method' => 'checkmo'],
            'store_id' => 1,
        ];
    }
}
