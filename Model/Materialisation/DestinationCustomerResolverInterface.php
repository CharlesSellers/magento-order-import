<?php
/**
 * Copyright © Venuno. All rights reserved.
 */

declare(strict_types=1);

namespace Venuno\OrderImport\Model\Materialisation;

/**
 * Magento-free seam over destination-customer identity lookups. Keeping the lookup behind a local
 * interface (like {@see NativeOrderGatewayInterface}) lets the link decision ({@see CustomerLinkPlanner})
 * be unit-tested without a Magento runtime; the Magento implementation
 * ({@see MagentoDestinationCustomerResolver}) is covered by the integration suite.
 *
 * Resolution is ALWAYS scoped to the order store's website: a customer account is unique per
 * (email, website) in Magento, so the same email can be different accounts on different websites.
 */
interface DestinationCustomerResolverInterface
{
    /**
     * @param string      $email                     customer-account email in the destination store
     * @param int         $websiteId                 order store's website; resolution is scoped to it
     * @param string|null $accountReferenceAttribute custom attribute to include on the resolved customer
     * @return ResolvedCustomer|null the matching destination customer, or null when none exists
     */
    public function resolveByEmailInWebsite(
        string $email,
        int $websiteId,
        ?string $accountReferenceAttribute
    ): ?ResolvedCustomer;

    /**
     * Resolve the canonical account reference inside one website. Implementations MUST reject an
     * ambiguous reference instead of choosing an arbitrary customer.
     */
    public function resolveByAccountReferenceInWebsite(
        string $accountReference,
        string $accountReferenceAttribute,
        int $websiteId
    ): ?ResolvedCustomer;
}
