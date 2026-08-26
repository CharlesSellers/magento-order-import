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
 * Rules (see ADR-0006):
 *  - A source guest may remain a guest only when `registration_required` is false.
 *  - A registration-required order MUST resolve to a destination customer and account reference.
 *  - Resolve independently by website-scoped email and, when supplied, account reference. Conflicting
 *    matches fail closed rather than attributing the order to the wrong account.
 *
 * Source customer ids/groups are provenance only — they are NEVER used for destination assignment.
 * The destination id and group come exclusively from the resolved {@see ResolvedCustomer}.
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
        if (!$draft->customerRegistrationRequired && $draft->sourceCustomerIsGuest) {
            return CustomerLinkPlan::guest();
        }

        // Any order that must link always carries a resolution email (OrderDraftBuilder enforces it).
        $email = $draft->customerAccountEmail ?? '';
        if ($email === '') {
            throw new MaterialisationException(
                'Non-guest customer block has no email to resolve the destination customer.',
                MaterialisationException::REASON_MISSING_FIELD,
                false
            );
        }

        $emailMatch = $this->resolver->resolveByEmailInWebsite(
            $email,
            $websiteId,
            $draft->accountReferenceAttribute
        );
        $referenceMatch = null;
        if ($draft->accountReference !== null && $draft->accountReferenceAttribute !== null) {
            $referenceMatch = $this->resolver->resolveByAccountReferenceInWebsite(
                $draft->accountReference,
                $draft->accountReferenceAttribute,
                $websiteId
            );
        }

        if ($emailMatch !== null && $referenceMatch !== null
            && $emailMatch->customerId !== $referenceMatch->customerId
        ) {
            throw new MaterialisationException(
                sprintf(
                    'Customer email and account reference resolve to different destination customers in website %d.',
                    $websiteId
                ),
                MaterialisationException::REASON_CUSTOMER_IDENTITY_CONFLICT,
                false
            );
        }

        $resolved = $referenceMatch ?? $emailMatch;
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

        $sourceReference = $this->nonEmpty($draft->accountReference);
        $destinationReference = $this->nonEmpty($resolved->accountReference);
        if ($sourceReference !== null && $destinationReference !== null
            && strcasecmp($sourceReference, $destinationReference) !== 0
        ) {
            throw new MaterialisationException(
                'Source and destination account references do not match for the resolved customer.',
                MaterialisationException::REASON_CUSTOMER_IDENTITY_CONFLICT,
                false
            );
        }

        $accountReference = $sourceReference ?? $destinationReference;
        if ($draft->customerRegistrationRequired && $accountReference === null) {
            throw new MaterialisationException(
                'Registration-required customer has no account reference in the source payload or destination account.',
                MaterialisationException::REASON_ACCOUNT_REFERENCE_MISSING,
                false
            );
        }

        $accountName = $this->nonEmpty($draft->customerAccountName)
            ?? $this->nonEmpty(trim(($resolved->firstname ?? '') . ' ' . ($resolved->lastname ?? '')));

        return CustomerLinkPlan::linked($resolved, $accountReference, $accountName);
    }

    private function nonEmpty(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed !== '' ? $trimmed : null;
    }
}
