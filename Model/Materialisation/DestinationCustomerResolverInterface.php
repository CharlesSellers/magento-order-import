<?php
/**
 * Copyright © Venuno. All rights reserved.
 */

declare(strict_types=1);

namespace Venuno\OrderImport\Model\Materialisation;

/**
 * Magento-free seam over "find the destination Magento customer for this email in this website". Keeping
 * the lookup behind a local interface (like {@see NativeOrderGatewayInterface}) lets the link decision
 * ({@see CustomerLinkPlanner}) be unit-tested without a Magento runtime; the Magento implementation
 * ({@see MagentoDestinationCustomerResolver}) is covered by the integration suite.
 *
 * Resolution is ALWAYS scoped to the order store's website: a customer account is unique per
 * (email, website) in Magento, so the same email can be different accounts on different websites.
 */
interface DestinationCustomerResolverInterface
{
    /**
     * @param string $email     the customer-account email to resolve against the DESTINATION store
     * @param int    $websiteId the order store's website; resolution is scoped to it
     * @return ResolvedCustomer|null the matching destination customer, or null when none exists
     */
    public function resolveByEmailInWebsite(string $email, int $websiteId): ?ResolvedCustomer;
}
