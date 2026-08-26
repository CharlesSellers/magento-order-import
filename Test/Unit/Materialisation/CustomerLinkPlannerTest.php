<?php
/**
 * Copyright © Venuno. All rights reserved.
 */

declare(strict_types=1);

namespace Venuno\OrderImport\Test\Unit\Materialisation;

use PHPUnit\Framework\TestCase;
use Venuno\OrderImport\Model\Materialisation\CustomerLinkPlanner;
use Venuno\OrderImport\Model\Materialisation\DestinationCustomerResolverInterface;
use Venuno\OrderImport\Model\Materialisation\MaterialisationException;
use Venuno\OrderImport\Model\Materialisation\OrderDraft;
use Venuno\OrderImport\Model\Materialisation\ResolvedCustomer;

/**
 * @covers \Venuno\OrderImport\Model\Materialisation\CustomerLinkPlanner
 * @covers \Venuno\OrderImport\Model\Materialisation\CustomerLinkPlan
 * @covers \Venuno\OrderImport\Model\Materialisation\ResolvedCustomer
 */
final class CustomerLinkPlannerTest extends TestCase
{
    public function testExplicitGuestStaysGuestAndNeverResolves(): void
    {
        $resolver = $this->createMock(DestinationCustomerResolverInterface::class);
        $resolver->expects(self::never())->method('resolveByEmailInWebsite');
        $resolver->expects(self::never())->method('resolveByAccountReferenceInWebsite');

        $plan = (new CustomerLinkPlanner($resolver))->plan($this->guestDraft(), 1);

        self::assertTrue($plan->isGuest);
        self::assertNull($plan->customer);
    }

    public function testMatchedNonGuestLinksToDestinationCustomer(): void
    {
        $resolver = $this->createMock(DestinationCustomerResolverInterface::class);
        // Deliberately a DIFFERENT group than the source group_id (3) carried on the draft — proving the
        // destination group is used, never the source's provenance value.
        $resolver->method('resolveByEmailInWebsite')
            ->willReturn(new ResolvedCustomer(99, 7, 'buyer@example.com', 'Bob', 'Buyer', 'ACC-99'));

        $plan = (new CustomerLinkPlanner($resolver))->plan($this->nonGuestDraft('buyer@example.com'), 1);

        self::assertFalse($plan->isGuest);
        self::assertNotNull($plan->customer);
        self::assertSame(99, $plan->customer->customerId);
        self::assertSame(7, $plan->customer->groupId);
        self::assertSame('buyer@example.com', $plan->customer->email);
        self::assertSame('ACC-99', $plan->accountReference);
        self::assertSame('Example Ltd', $plan->accountName);
    }

    public function testUnmatchedNonGuestFailsTerminally(): void
    {
        $resolver = $this->createMock(DestinationCustomerResolverInterface::class);
        $resolver->method('resolveByEmailInWebsite')->willReturn(null);

        try {
            (new CustomerLinkPlanner($resolver))->plan($this->nonGuestDraft('missing@example.com'), 1);
            self::fail('expected a terminal MaterialisationException for an unmatched non-guest');
        } catch (MaterialisationException $e) {
            self::assertSame(MaterialisationException::REASON_CUSTOMER_NOT_FOUND, $e->getReason());
            self::assertFalse($e->isRetryable(), 'an unmatched non-guest fails the import terminally; it is not retryable');
        }
    }

    public function testResolvesWithinTheOrdersWebsite(): void
    {
        $resolver = $this->createMock(DestinationCustomerResolverInterface::class);
        $resolver->expects(self::once())
            ->method('resolveByEmailInWebsite')
            ->with('buyer@example.com', 4, 'short_account_ref')
            ->willReturn(new ResolvedCustomer(1, 1, 'buyer@example.com', null, null, 'ACC-1'));

        $plan = (new CustomerLinkPlanner($resolver))->plan($this->nonGuestDraft('buyer@example.com'), 4);

        self::assertFalse($plan->isGuest);
    }

    public function testRegistrationRequiredSourceGuestMustResolveAndNeverFallsBackToGuest(): void
    {
        $resolver = $this->createMock(DestinationCustomerResolverInterface::class);
        $resolver->expects(self::once())
            ->method('resolveByEmailInWebsite')
            ->with('guest-source@example.com', 1, 'short_account_ref')
            ->willReturn(new ResolvedCustomer(27, 4, 'guest-source@example.com', 'Guest', 'Source', 'ACC-27'));

        $plan = (new CustomerLinkPlanner($resolver))->plan(
            $this->draft(true, true, 'guest-source@example.com'),
            1
        );

        self::assertFalse($plan->isGuest);
        self::assertSame(27, $plan->customer?->customerId);
        self::assertSame('ACC-27', $plan->accountReference);
    }

    public function testRegistrationRequiredCustomerWithoutAnyAccountReferenceFailsTerminally(): void
    {
        $resolver = $this->createMock(DestinationCustomerResolverInterface::class);
        $resolver->method('resolveByEmailInWebsite')
            ->willReturn(new ResolvedCustomer(99, 7, 'buyer@example.com', 'Bob', 'Buyer'));

        try {
            (new CustomerLinkPlanner($resolver))->plan($this->nonGuestDraft('buyer@example.com'), 1);
            self::fail('expected a terminal account-reference failure');
        } catch (MaterialisationException $e) {
            self::assertSame(MaterialisationException::REASON_ACCOUNT_REFERENCE_MISSING, $e->getReason());
            self::assertFalse($e->isRetryable());
        }
    }

    public function testConflictingEmailAndAccountReferenceMatchesFailClosed(): void
    {
        $resolver = $this->createMock(DestinationCustomerResolverInterface::class);
        $resolver->method('resolveByEmailInWebsite')
            ->willReturn(new ResolvedCustomer(10, 2, 'buyer@example.com', null, null, 'ACC-10'));
        $resolver->method('resolveByAccountReferenceInWebsite')
            ->willReturn(new ResolvedCustomer(11, 2, 'other@example.com', null, null, 'ACC-11'));

        try {
            (new CustomerLinkPlanner($resolver))->plan(
                $this->draft(false, true, 'buyer@example.com', 'ACC-11'),
                1
            );
            self::fail('expected a terminal identity-conflict failure');
        } catch (MaterialisationException $e) {
            self::assertSame(MaterialisationException::REASON_CUSTOMER_IDENTITY_CONFLICT, $e->getReason());
            self::assertFalse($e->isRetryable());
        }
    }

    private function guestDraft(): OrderDraft
    {
        return $this->draft(true, false, null);
    }

    private function nonGuestDraft(string $email): OrderDraft
    {
        return $this->draft(false, true, $email);
    }

    private function draft(
        bool $sourceIsGuest,
        bool $registrationRequired,
        ?string $accountEmail,
        ?string $accountReference = null
    ): OrderDraft {
        return new OrderDraft(
            storeId: 4,
            extOrderId: '100000123',
            sourcePlatform: 'magento',
            sourceIncrementId: '100000123',
            sourceEntityId: '71951',
            currencyCode: 'GBP',
            customerEmail: 'order@example.com',
            customerFirstname: 'Order',
            customerLastname: 'Contact',
            isVirtual: false,
            billingAddress: [],
            shippingAddress: null,
            shippingMethod: null,
            shippingDescription: null,
            items: [],
            totals: [],
            paymentMethod: 'checkmo',
            sourceCustomerIsGuest: $sourceIsGuest,
            customerRegistrationRequired: $registrationRequired,
            customerAccountEmail: $accountEmail,
            customerAccountName: 'Example Ltd',
            accountReference: $accountReference,
            accountReferenceAttribute: 'short_account_ref',
            sourceCustomerId: '555',
            sourceGroupId: '3'
        );
    }
}
