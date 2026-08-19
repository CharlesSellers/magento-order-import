<?php
/**
 * Copyright © Venuno. All rights reserved.
 */

declare(strict_types=1);

namespace Venuno\OrderImport\Model\Materialisation;

/**
 * Immutable, Magento-free description of a DESTINATION Magento customer that a non-guest import resolved
 * to. Produced by {@see DestinationCustomerResolverInterface} (from a live Magento in the concrete
 * implementation) and consumed by {@see CustomerLinkPlanner} / {@see NativeOrderGatewayInterface}.
 *
 * The ids here are ALWAYS destination-side (customer_id, group_id looked up in B). The source's
 * `source_customer_id` / `group_id` are provenance only and never appear on this object — see
 * {@see OrderDraft} for why they must not be reused as destination ids.
 */
final class ResolvedCustomer
{
    public function __construct(
        public readonly int $customerId,
        public readonly int $groupId,
        public readonly string $email,
        public readonly ?string $firstname,
        public readonly ?string $lastname
    ) {
    }
}
