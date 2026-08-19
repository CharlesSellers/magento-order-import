<?php
/**
 * Copyright © Venuno. All rights reserved.
 */

declare(strict_types=1);

namespace Venuno\OrderImport\Model\Materialisation;

/**
 * Decides how one order's customer is treated (0.4 customer linking). Pure PHP over the
 * {@see DestinationCustomerResolverInterface} seam, so every branch is unit-testable without Magento.
 *
 * Rules (see docs/adr — customer linking):
 *  - Explicit genuine guest ({@see OrderDraft::$customerIsGuest} true) → stays a guest; NO lookup.
 *  - Non-guest → resolve the DESTINATION customer by the account email within the ORDER STORE'S WEBSITE.
 *      - match   → link to that destination customer (its id + its group).
 *      - no match → terminal {@see MaterialisationException} (REASON_CUSTOMER_NOT_FOUND, non-retryable)
 *                   so the module fails the import terminally rather than silently creating a guest.
 *
 * The source `source_customer_id` and `group_id` are provenance only — they are NEVER read here. The
 * destination id and destination group come exclusively from the resolved {@see ResolvedCustomer}.
 */
class CustomerLinkPlanner
{
    public function __construct(
        private readonly DestinationCustomerResolverInterface $resolver
    ) {
    }

    /**
     * @param int $websiteId the order store's website (resolution is scoped to it)
     * @throws MaterialisationException when a non-guest has no matching destination customer (terminal)
     */
    public function plan(OrderDraft $draft, int $websiteId): CustomerLinkPlan
    {
        if ($draft->customerIsGuest) {
            return CustomerLinkPlan::guest();
        }

        // Non-guest. A non-guest draft always carries a resolution email (OrderDraftBuilder enforces it).
        $email = $draft->customerAccountEmail ?? '';
        if ($email === '') {
            throw new MaterialisationException(
                'Non-guest customer block has no email to resolve the destination customer.',
                MaterialisationException::REASON_MISSING_FIELD,
                false
            );
        }

        $resolved = $this->resolver->resolveByEmailInWebsite($email, $websiteId);
        if ($resolved === null) {
            throw new MaterialisationException(
                sprintf(
                    'No destination customer for a non-guest order (email resolved in website %d found no account).',
                    $websiteId
                ),
                MaterialisationException::REASON_CUSTOMER_NOT_FOUND,
                false
            );
        }

        return CustomerLinkPlan::linked($resolved);
    }
}
