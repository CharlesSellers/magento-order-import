<?php
/**
 * Copyright © Venuno. All rights reserved.
 */

declare(strict_types=1);

namespace Venuno\OrderImport\Test\Integration\Materialisation;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Model\Group;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;
use Venuno\OrderImport\Model\Materialisation\MaterialisationException;
use Venuno\OrderImport\Model\Materialisation\OrderMaterialiser;
use Venuno\OrderImport\Model\OrderImportRepository;

/**
 * End-to-end validation of 0.4 customer linking against a REAL Magento — customer resolution and
 * {@see \Venuno\OrderImport\Model\Materialisation\NativeOrderGateway} are Magento-coupled, so ADR-0003
 * requires this live check. Runs only under Magento's integration framework (`dev/tests/integration`);
 * it is intentionally NOT part of the Magento-free `Unit` suite (see Test/Integration/README.md).
 *
 * @magentoDbIsolation enabled
 * @magentoAppIsolation enabled
 */
final class CustomerLinkingIntegrationTest extends TestCase
{
    private OrderImportRepository $repository;
    private OrderMaterialiser $materialiser;
    private OrderRepositoryInterface $orders;
    private CustomerRepositoryInterface $customers;

    protected function setUp(): void
    {
        $om = Bootstrap::getObjectManager();
        $this->repository = $om->get(OrderImportRepository::class);
        $this->materialiser = $om->get(OrderMaterialiser::class);
        $this->orders = $om->get(OrderRepositoryInterface::class);
        $this->customers = $om->get(CustomerRepositoryInterface::class);
    }

    /**
     * A non-guest order links to the destination customer resolved by (email, website) — with the
     * DESTINATION group, never the source group_id carried in the payload.
     *
     * @magentoDataFixture Magento/Catalog/_files/product_simple.php
     * @magentoDataFixture Magento/Customer/_files/customer.php
     */
    public function testNonGuestLinksToDestinationCustomerInTheOrdersWebsite(): void
    {
        $expected = $this->customers->get('customer@example.com', 1); // fixture customer, website 1

        $key = 'magento:link-' . uniqid();
        $this->stage($key, $this->order('simple', [
            'is_guest' => false,
            'email' => 'customer@example.com',
            'group_id' => '999',          // provenance only — must be ignored
            'source_customer_id' => '777', // provenance only — must be ignored
        ]));

        $result = $this->materialiser->materialise($key);
        $order = $this->orders->get($result->magentoOrderId);

        self::assertFalse((bool) $order->getCustomerIsGuest());
        self::assertSame((int) $expected->getId(), (int) $order->getCustomerId());
        self::assertSame(
            (int) $expected->getGroupId(),
            (int) $order->getCustomerGroupId(),
            'group comes from the resolved destination customer, not the source group_id'
        );
    }

    /**
     * An explicit guest stays a guest (NOT_LOGGED_IN, no customer id).
     *
     * @magentoDataFixture Magento/Catalog/_files/product_simple.php
     */
    public function testExplicitGuestCreatesAGuestOrder(): void
    {
        $key = 'magento:guest-' . uniqid();
        $this->stage($key, $this->order('simple', ['is_guest' => true, 'email' => 'ada@example.com']));

        $result = $this->materialiser->materialise($key);
        $order = $this->orders->get($result->magentoOrderId);

        self::assertTrue((bool) $order->getCustomerIsGuest());
        self::assertEmpty($order->getCustomerId());
        self::assertSame(Group::NOT_LOGGED_IN_ID, (int) $order->getCustomerGroupId());
    }

    /**
     * A non-guest with no matching destination customer fails terminally and creates NO order (the
     * import fails terminally via the existing failure path) — never a silent guest.
     *
     * @magentoDataFixture Magento/Catalog/_files/product_simple.php
     */
    public function testUnmatchedNonGuestFailsTerminallyAndCreatesNoOrder(): void
    {
        $key = 'magento:unmatched-' . uniqid();
        $this->stage($key, $this->order('simple', [
            'is_guest' => false,
            'email' => 'nobody-' . uniqid() . '@example.com',
        ]));

        try {
            $this->materialiser->materialise($key);
            self::fail('expected a terminal MaterialisationException for an unmatched non-guest');
        } catch (MaterialisationException $e) {
            self::assertSame(MaterialisationException::REASON_CUSTOMER_NOT_FOUND, $e->getReason());
            self::assertFalse($e->isRetryable());
        }

        $row = $this->repository->findByReplayKey($key);
        self::assertSame('failed', $row['import_status'], 'the attempt remains in failed status for replay');
        self::assertSame(0, (int) $row['magento_order_id'], 'no orphan order — transactional rollback');
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
     * @param array<string, mixed> $customer the sibling `customer` block (0.4)
     * @return array<string, mixed>
     */
    private function order(string $sku, array $customer): array
    {
        return [
            'header' => ['increment_id' => '100000123', 'order_currency_code' => 'USD', 'is_virtual' => 0, 'store_id' => 1],
            'customer' => $customer,
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
