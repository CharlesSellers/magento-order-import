<?php
/**
 * Copyright © Venuno. All rights reserved.
 */

declare(strict_types=1);

namespace Venuno\OrderImport\Model\Materialisation;

use Magento\Customer\Api\CustomerRepositoryInterface;
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
        private readonly CustomerRepositoryInterface $customerRepository
    ) {
    }

    public function resolveByEmailInWebsite(string $email, int $websiteId): ?ResolvedCustomer
    {
        try {
            $customer = $this->customerRepository->get($email, $websiteId);
        } catch (NoSuchEntityException) {
            // No account for this email in this website → not an error; the planner decides the outcome.
            return null;
        }

        return new ResolvedCustomer(
            (int) $customer->getId(),
            (int) $customer->getGroupId(),
            (string) $customer->getEmail(),
            $customer->getFirstname() !== null ? (string) $customer->getFirstname() : null,
            $customer->getLastname() !== null ? (string) $customer->getLastname() : null
        );
    }
}
