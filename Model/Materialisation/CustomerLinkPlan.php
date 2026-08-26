<?php
/**
 * Copyright © Venuno. All rights reserved.
 */

declare(strict_types=1);

namespace Venuno\OrderImport\Model\Materialisation;

/**
 * The decided customer treatment for one order: either it stays a guest, or it links to a specific
 * DESTINATION customer ({@see ResolvedCustomer}). Produced by {@see CustomerLinkPlanner} and applied by
 * {@see NativeOrderGatewayInterface}. Immutable and Magento-free.
 */
final class CustomerLinkPlan
{
    private function __construct(
        public readonly bool $isGuest,
        public readonly ?ResolvedCustomer $customer,
        public readonly ?string $accountReference,
        public readonly ?string $accountName
    ) {
    }

    public static function guest(): self
    {
        return new self(true, null, null, null);
    }

    public static function linked(
        ResolvedCustomer $customer,
        ?string $accountReference,
        ?string $accountName
    ): self {
        return new self(false, $customer, $accountReference, $accountName);
    }
}
