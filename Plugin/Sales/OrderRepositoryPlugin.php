<?php
/**
 * Copyright © Venuno. All rights reserved.
 */

declare(strict_types=1);

namespace Venuno\OrderImport\Plugin\Sales;

use Magento\Sales\Api\Data\OrderExtensionFactory;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

/** Exposes Venuno's persisted account identity on the standard sales-order REST response. */
class OrderRepositoryPlugin
{
    public function __construct(
        private readonly OrderExtensionFactory $extensionFactory
    ) {
    }

    public function afterGet(OrderRepositoryInterface $subject, OrderInterface $order): OrderInterface
    {
        return $this->hydrate($order);
    }

    public function afterGetList(
        OrderRepositoryInterface $subject,
        OrderSearchResultInterface $searchResult
    ): OrderSearchResultInterface {
        foreach ($searchResult->getItems() as $order) {
            $this->hydrate($order);
        }
        return $searchResult;
    }

    private function hydrate(OrderInterface $order): OrderInterface
    {
        $extensionAttributes = $order->getExtensionAttributes() ?? $this->extensionFactory->create();

        // The repository returns Magento\Sales\Model\Order, whose data bag contains both declarative
        // sales_order columns. Keeping the REST contract in extension_attributes avoids changing core
        // service interfaces while making the values available to the SFTP/XML consumer.
        if (method_exists($order, 'getData')) {
            $reference = $order->getData('venuno_account_reference');
            $name = $order->getData('venuno_account_name');
            $extensionAttributes->setVenunoAccountReference(
                $reference !== null && trim((string) $reference) !== '' ? trim((string) $reference) : null
            );
            $extensionAttributes->setVenunoAccountName(
                $name !== null && trim((string) $name) !== '' ? trim((string) $name) : null
            );
        }

        $order->setExtensionAttributes($extensionAttributes);
        return $order;
    }
}
