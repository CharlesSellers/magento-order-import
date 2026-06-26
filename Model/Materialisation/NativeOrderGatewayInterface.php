<?php
/**
 * Copyright © Venuno. All rights reserved.
 */

declare(strict_types=1);

namespace Venuno\OrderImport\Model\Materialisation;

/**
 * Creates a native Magento sales order from an {@see OrderDraft}. The signature is Magento-free so the
 * orchestration ({@see OrderMaterialiser}) can be unit-tested against a mock; the real implementation
 * ({@see NativeOrderGateway}) is exercised by Magento integration tests.
 */
interface NativeOrderGatewayInterface
{
    /**
     * Create the native order and return its Magento entity id.
     *
     * @throws MaterialisationException terminal (e.g. unknown SKU) or retryable (transient save failure)
     */
    public function place(OrderDraft $draft): int;
}
