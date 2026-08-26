<?php
/**
 * Copyright © Venuno. All rights reserved.
 */

declare(strict_types=1);

namespace Venuno\OrderImport\Model\Materialisation;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Magento implementation of {@see DestinationCustomerResolverInterface}: resolves a destination customer
 * by (email, website) via the customer repository. A missing account is a clean `null` (the planner turns
 * that into a terminal "customer not found"); any other repository error propagates so the orchestrator
 * classifies it (a transient failure is retryable).
 *
 * Magento-coupled → exercised by the integration suite, per the module's testing contract (ADR-0003).
 */
class MagentoDestinationCustomerResolver implements DestinationCustomerResolverInterface
{
    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    public function resolveByEmailInWebsite(
        string $email,
        int $websiteId,
        ?string $accountReferenceAttribute
    ): ?ResolvedCustomer {
        try {
            $customer = $this->customerRepository->get($email, $websiteId);
        } catch (NoSuchEntityException) {
            // No account for this email in this website → not an error; the planner decides the outcome.
            return null;
        }

        return $this->toResolvedCustomer($customer, $accountReferenceAttribute);
    }

    public function resolveByAccountReferenceInWebsite(
        string $accountReference,
        string $accountReferenceAttribute,
        int $websiteId
    ): ?ResolvedCustomer {
        $criteria = $this->searchCriteriaBuilder
            ->addFilter('website_id', $websiteId)
            ->addFilter($accountReferenceAttribute, $accountReference)
            ->setPageSize(2)
            ->create();
        $customers = array_values($this->customerRepository->getList($criteria)->getItems());

        if (count($customers) > 1) {
            throw new MaterialisationException(
                sprintf(
                    'Account reference resolves to more than one destination customer in website %d.',
                    $websiteId
                ),
                MaterialisationException::REASON_CUSTOMER_AMBIGUOUS,
                false
            );
        }
        if ($customers === []) {
            return null;
        }

        return $this->toResolvedCustomer($customers[0], $accountReferenceAttribute);
    }

    private function toResolvedCustomer(
        CustomerInterface $customer,
        ?string $accountReferenceAttribute
    ): ResolvedCustomer {
        $accountReference = null;
        if ($accountReferenceAttribute !== null) {
            $attribute = $customer->getCustomAttribute($accountReferenceAttribute);
            if ($attribute !== null && trim((string) $attribute->getValue()) !== '') {
                $accountReference = trim((string) $attribute->getValue());
            }
        }

        return new ResolvedCustomer(
            (int) $customer->getId(),
            (int) $customer->getGroupId(),
            (string) $customer->getEmail(),
            $customer->getFirstname() !== null ? (string) $customer->getFirstname() : null,
            $customer->getLastname() !== null ? (string) $customer->getLastname() : null,
            $accountReference
        );
    }
}
