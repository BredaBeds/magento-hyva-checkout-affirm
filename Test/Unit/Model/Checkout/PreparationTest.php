<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Test\Unit\Model\Checkout;

use DateTimeInterface;
use Hyva\CheckoutAffirm\Model\Attempt\Attempt;
use Hyva\CheckoutAffirm\Model\Attempt\AttemptRepositoryInterface;
use Hyva\CheckoutAffirm\Model\Attempt\OwnerKeyInterface;
use Hyva\CheckoutAffirm\Model\Attempt\SnapshotInterface;
use Hyva\CheckoutAffirm\Model\Attempt\State;
use Hyva\CheckoutAffirm\Model\Checkout\AccountingException;
use Hyva\CheckoutAffirm\Model\Checkout\CheckoutObjectBuilderInterface;
use Hyva\CheckoutAffirm\Model\Checkout\ContactBuilder;
use Hyva\CheckoutAffirm\Model\Checkout\GuestCheckout;
use Hyva\CheckoutAffirm\Model\Checkout\Preparation;
use Magento\Checkout\Helper\Data as CheckoutHelper;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Payment\Model\Checks\Composite;
use Magento\Payment\Model\Checks\SpecificationFactory;
use Magento\Payment\Model\MethodInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Payment;
use Magento\Quote\Model\ResourceModel\Quote as QuoteResource;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PreparationTest extends TestCase
{
    private const QUOTE_ID = 42;
    private const STORE_ID = 1;
    private const RESERVED = '000000123';
    private const OWNER = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const SNAPSHOT = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const ATTEMPT_ID = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
    private const TOTAL = 132025;

    /** @var Quote&MockObject */
    private Quote $quote;

    /** @var Payment&MockObject */
    private Payment $payment;

    /** @var MethodInterface&MockObject */
    private MethodInterface $paymentMethod;

    /** @var Composite&MockObject */
    private Composite $specification;

    /** @var CheckoutObjectBuilderInterface&MockObject */
    private CheckoutObjectBuilderInterface $builder;

    /** @var ContactBuilder&MockObject */
    private ContactBuilder $contactBuilder;

    /** @var AttemptRepositoryInterface&MockObject */
    private AttemptRepositoryInterface $attempts;

    /** @var SnapshotInterface&MockObject */
    private SnapshotInterface $snapshot;

    /** @var QuoteResource&MockObject */
    private QuoteResource $quoteResource;

    /** @var CheckoutHelper&MockObject */
    private CheckoutHelper $checkoutHelper;

    /** @var LockManagerInterface&MockObject */
    private LockManagerInterface $lockManager;

    /** @var list<string> */
    private array $locks = [];

    /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> */
    private array $logs = [];

    protected function setUp(): void
    {
        $this->paymentMethod = $this->createMock(MethodInterface::class);
        $this->paymentMethod->method('isAvailable')->willReturn(true);

        $this->payment = $this->createMock(Payment::class);
        $this->payment->method('getMethod')->willReturn('affirm_gateway');
        $this->payment->method('getMethodInstance')->willReturn($this->paymentMethod);

        $this->quote = $this->quoteMock();
        $this->quote->setData(['store_id' => self::STORE_ID, 'customer_id' => null]);

        $this->specification = $this->createMock(Composite::class);
        $this->specification->method('isApplicable')->willReturn(true);

        $this->builder = $this->createMock(CheckoutObjectBuilderInterface::class);
        $this->contactBuilder = $this->createMock(ContactBuilder::class);
        $this->attempts = $this->createMock(AttemptRepositoryInterface::class);
        $this->snapshot = $this->createMock(SnapshotInterface::class);
        $this->snapshot->method('hash')->willReturn(self::SNAPSHOT);
        $this->quoteResource = $this->createMock(QuoteResource::class);
        $this->checkoutHelper = $this->createMock(CheckoutHelper::class);
        $this->checkoutHelper->method('isAllowedGuestCheckout')->willReturn(true);

        $this->lockManager = $this->createMock(LockManagerInterface::class);
        $this->lockManager->method('lock')->willReturnCallback(function (string $name, int $timeout): bool {
            $this->locks[] = 'lock ' . $name;

            return true;
        });
        $this->lockManager->method('unlock')->willReturnCallback(function (string $name): bool {
            $this->locks[] = 'unlock ' . $name;

            return true;
        });
    }

    public function testCreatesAnAttemptAndBuildsTheCheckout(): void
    {
        $this->attempts->method('findReusable')->with(self::QUOTE_ID, self::OWNER, self::SNAPSHOT)->willReturn(null);
        $this->attempts->expects($this->once())->method('countRecentForQuote')
            ->willReturnCallback(function (int $quoteId, DateTimeInterface $since): int {
                $this->assertSame(self::QUOTE_ID, $quoteId);
                $this->assertEqualsWithDelta(time() - 3600, $since->getTimestamp(), 5);

                return 3;
            });
        $this->attempts->expects($this->once())->method('supersedeOthersForQuote')
            ->with(self::QUOTE_ID, self::ATTEMPT_ID)
            ->willReturnCallback(function (): int {
                $this->assertSame(['lock hyva_affirm_prepare_42'], $this->locks, 'superseded under the cart lock');

                return 1;
            });
        $this->attempts->expects($this->once())->method('create')
            ->with(
                self::QUOTE_ID,
                self::STORE_ID,
                null,
                self::RESERVED,
                'USD',
                self::TOTAL,
                self::SNAPSHOT,
                self::OWNER,
                'affirm_gateway'
            )
            ->willReturn($this->attempt());

        $checkout = $this->checkoutObject('redirect');
        $this->builder->expects($this->exactly(2))->method('build')->willReturnCallback(
            function (Quote $quote, string $mode, string $attemptId) use ($checkout): array {
                $this->assertSame(self::RESERVED, $quote->getData('reserved_order_id'), 'reserved before building');
                if ($mode === 'inline') {
                    $this->assertSame('', $attemptId);

                    return $this->checkoutObject('inline');
                }
                $this->assertSame('redirect', $mode);
                $this->assertSame(self::ATTEMPT_ID, $attemptId);

                return $checkout;
            }
        );
        $this->contactBuilder->expects($this->once())->method('build')->with($this->quote);
        $this->quoteResource->expects($this->once())->method('save')->with($this->quote);

        [$built, $attempt] = $this->preparation()->prepare($this->quote, 'redirect');

        $this->assertSame($checkout, $built);
        $this->assertSame(self::ATTEMPT_ID, $attempt->attemptId);
        $this->assertSame([false], $this->quote->getData('collected_with_flag'));
        $this->assertSame(['lock hyva_affirm_prepare_42', 'unlock hyva_affirm_prepare_42'], $this->locks);
    }

    public function testCustomerAttemptsCarryTheCustomerId(): void
    {
        $this->quote->setData('customer_id', '7');
        $this->checkoutHelper = $this->createMock(CheckoutHelper::class);
        $this->checkoutHelper->expects($this->never())->method('isAllowedGuestCheckout');
        $this->attempts->method('countRecentForQuote')->willReturn(0);
        $this->attempts->expects($this->once())->method('create')
            ->with(self::QUOTE_ID, self::STORE_ID, 7)
            ->willReturn($this->attempt());
        $this->builder->method('build')->willReturn($this->checkoutObject('modal'));

        [, $attempt] = $this->preparation()->prepare($this->quote, 'modal');

        $this->assertSame(self::ATTEMPT_ID, $attempt->attemptId);
    }

    public function testReusesAnInitiatedAttemptForTheSameCart(): void
    {
        $this->quote->setData('reserved_order_id', self::RESERVED);
        $this->attempts->method('findReusable')->willReturn($this->attempt());
        $this->attempts->expects($this->never())->method('countRecentForQuote');
        $this->attempts->expects($this->never())->method('create');
        $this->attempts->expects($this->never())->method('transition');
        $this->quoteResource->expects($this->never())->method('save');
        $this->builder->expects($this->once())->method('build')
            ->with($this->quote, 'modal', self::ATTEMPT_ID)
            ->willReturn($this->checkoutObject('modal'));

        [, $attempt] = $this->preparation()->prepare($this->quote, 'modal');

        $this->assertSame(self::ATTEMPT_ID, $attempt->attemptId);
    }

    public function testDoesNotReuseAnAttemptForAnotherOrderNumber(): void
    {
        $this->quote->setData('reserved_order_id', '000000999');
        $this->attempts->method('findReusable')->willReturn($this->attempt());
        $this->attempts->method('countRecentForQuote')->willReturn(0);
        $newAttempt = $this->attempt(str_repeat('e', 64), State::INITIATED, '000000999');
        $this->attempts->expects($this->once())->method('create')->willReturn($newAttempt);
        $this->builder->method('build')->willReturn($this->checkoutObject('redirect'));

        [, $attempt] = $this->preparation()->prepare($this->quote, 'redirect');

        $this->assertSame($newAttempt->attemptId, $attempt->attemptId);
    }

    public function testGuestCheckoutTheStoreForbidsIsRefusedBeforeAnyWrite(): void
    {
        $this->checkoutHelper = $this->createMock(CheckoutHelper::class);
        $this->checkoutHelper->expects($this->once())->method('isAllowedGuestCheckout')->with($this->quote)
            ->willReturn(false);
        $this->contactBuilder->expects($this->never())->method('build');
        $this->expectNoWrites();

        $this->assertSame('Please sign in or create an account to place this order.', $this->prepareError());
    }

    public function testCartErrorsAreFoundBeforeAffirmOpens(): void
    {
        $this->quote->setData('has_error', true);
        $this->expectNoWrites();

        $this->assertStringStartsWith('Some items in your cart need attention.', $this->prepareError());
    }

    public function testCartBelowTheMinimumOrderAmountIsRefused(): void
    {
        $this->quote = $this->quoteMock(minimumAmountMet: false);
        $this->quote->setData(['store_id' => self::STORE_ID]);
        $this->expectNoWrites();

        $this->assertStringStartsWith('Your cart is below the minimum order amount.', $this->prepareError());
    }

    public function testUnavailableMethodStopsBeforeAnyWrite(): void
    {
        $this->paymentMethod = $this->createMock(MethodInterface::class);
        $this->paymentMethod->method('isAvailable')->willReturn(false);
        $this->payment = $this->createMock(Payment::class);
        $this->payment->method('getMethod')->willReturn('affirm_gateway');
        $this->payment->method('getMethodInstance')->willReturn($this->paymentMethod);
        $this->rebindPayment();
        $this->expectNoWrites();

        $this->assertSame(
            'Affirm is not available for this cart. Please choose another payment method.',
            $this->prepareError()
        );
    }

    public function testMagentosMethodChecksAreApplied(): void
    {
        $this->specification = $this->createMock(Composite::class);
        $this->specification->method('isApplicable')->willReturn(false);
        $this->expectNoWrites();

        $this->assertStringStartsWith('Affirm is not available', $this->prepareError());
    }

    public function testAnotherSelectedMethodIsRefused(): void
    {
        $this->payment = $this->createMock(Payment::class);
        $this->payment->method('getMethod')->willReturn('checkmo');
        $this->rebindPayment();
        $this->expectNoWrites();

        $this->assertSame('Your payment method changed. Please select Affirm again.', $this->prepareError());
    }

    public function testIncompleteCheckoutDataStopsBeforeTheReservation(): void
    {
        $this->contactBuilder->method('build')
            ->willThrowException(new AccountingException(AccountingException::REASON_MISSING_EMAIL));
        $this->quote->expects($this->never())->method('reserveOrderId');
        $this->expectNoWrites();

        $this->assertSame(
            'Please complete your contact, address and shipping details before paying with Affirm.',
            $this->prepareError()
        );
    }

    public function testUnresolvedAttemptBlocksANewOne(): void
    {
        $this->attempts->method('hasUnresolvedForQuote')->with(self::QUOTE_ID)->willReturn(true);
        $this->quote->expects($this->never())->method('reserveOrderId');
        $this->attempts->expects($this->never())->method('create');
        $this->builder->expects($this->never())->method('build');

        $this->assertStringContainsString('still being confirmed', $this->prepareError());
    }

    public function testAPreparationOfTheSameCartThatHoldsTheLockStopsThisOne(): void
    {
        $this->lockManager = $this->createMock(LockManagerInterface::class);
        $this->lockManager->expects($this->once())->method('lock')->with('hyva_affirm_prepare_42', 10)
            ->willReturn(false);
        $this->lockManager->expects($this->never())->method('unlock');
        $this->attempts->expects($this->never())->method('findReusable');
        $this->attempts->expects($this->never())->method('create');
        $this->attempts->expects($this->never())->method('supersedeOthersForQuote');

        $this->assertStringStartsWith('Affirm is still being prepared for this cart.', $this->prepareError());
    }

    public function testTheLockIsReleasedWhenTheAttemptCannotBeCreated(): void
    {
        $this->attempts->method('countRecentForQuote')->willReturn(10);

        $this->assertStringStartsWith('Too many Affirm payment attempts', $this->prepareError());
        $this->assertSame(['lock hyva_affirm_prepare_42', 'unlock hyva_affirm_prepare_42'], $this->locks);
    }

    public function testThrottlesNewAttemptsPerCart(): void
    {
        $this->attempts->method('countRecentForQuote')->willReturn(10);
        $this->attempts->expects($this->never())->method('create');
        $this->attempts->expects($this->never())->method('transition');
        $this->builder->expects($this->never())->method('build');

        $this->assertStringStartsWith('Too many Affirm payment attempts', $this->prepareError());
        $this->assertSame('warning', $this->logs[0][0]);
        $this->assertSame(['quote_id' => self::QUOTE_ID, 'limit' => 10], $this->logs[0][2]);
    }

    public function testTheThrottleIsConfigurable(): void
    {
        $this->attempts->method('countRecentForQuote')->willReturn(10);
        $this->attempts->expects($this->once())->method('create')->willReturn($this->attempt());
        $this->builder->method('build')->willReturn($this->checkoutObject('redirect'));

        [, $attempt] = $this->preparation(11)->prepare($this->quote, 'redirect');

        $this->assertSame(self::ATTEMPT_ID, $attempt->attemptId);
    }

    public function testDeclinedCartCreatesNoAttempt(): void
    {
        $this->attempts->method('countRecentForQuote')->willReturn(0);
        $this->builder->method('build')
            ->willThrowException(new AccountingException(AccountingException::REASON_UNSUPPORTED_ADJUSTMENT));
        $this->attempts->expects($this->never())->method('create');
        $this->attempts->expects($this->never())->method('transition');

        $this->assertSame('Affirm is not available for this cart.', $this->prepareError());
    }

    public function testDeclinedReusedAttemptReportsTheBuilderMessage(): void
    {
        $this->quote->setData('reserved_order_id', self::RESERVED);
        $this->attempts->method('findReusable')->willReturn($this->attempt());
        $this->builder->method('build')
            ->willThrowException(new AccountingException(AccountingException::REASON_UNSUPPORTED_CURRENCY));

        $this->assertSame('Affirm is not available for the currency of this cart.', $this->prepareError());
    }

    public function testTotalsThatDifferFromTheAttemptFailIt(): void
    {
        $this->quote->setData('reserved_order_id', self::RESERVED);
        $this->attempts->method('findReusable')->willReturn($this->attempt());
        $checkout = $this->checkoutObject('redirect');
        $checkout['total'] = self::TOTAL + 1;
        $this->builder->method('build')->willReturn($checkout);
        $this->attempts->expects($this->once())->method('transition')->with(
            self::ATTEMPT_ID,
            State::open(),
            State::FAILED,
            ['failure_reason' => Attempt::REASON_CART_CHANGED]
        );

        $this->assertStringStartsWith('Your cart changed', $this->prepareError());
    }

    public function testEmptyCartIsRefused(): void
    {
        $this->quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'hasItems', 'getPayment'])
            ->getMock();
        $this->quote->method('getId')->willReturn(null);
        $this->quote->method('getPayment')->willReturn($this->payment);
        $this->expectNoWrites();

        $this->assertSame('Your cart is empty or has expired. Please reload the page.', $this->prepareError());
    }

    public function testAvailabilityIsFalseForAnotherMethod(): void
    {
        $this->payment = $this->createMock(Payment::class);
        $this->payment->method('getMethod')->willReturn('checkmo');
        $this->payment->expects($this->never())->method('getMethodInstance');
        $this->rebindPayment();

        $this->assertFalse($this->preparation()->isAvailable($this->quote));
    }

    public function testReserveOrderIdSavesTheQuoteOnlyWhenTheNumberChanged(): void
    {
        $this->quoteResource->expects($this->once())->method('save')->with($this->quote);
        $preparation = $this->preparation();

        $this->assertSame(self::RESERVED, $preparation->reserveOrderId($this->quote));
        $this->assertSame(self::RESERVED, $preparation->reserveOrderId($this->quote));
        $this->assertSame(self::RESERVED, $preparation->reservedOrderId($this->quote));
    }

    /**
     * The shopper message prepare() refuses the cart with.
     */
    private function prepareError(string $mode = 'redirect'): string
    {
        try {
            $this->preparation()->prepare($this->quote, $mode);
        } catch (LocalizedException $e) {
            return $e->getMessage();
        }
        $this->fail('prepare() was expected to refuse the cart.');
    }

    private function preparation(int $attemptLimit = 10): Preparation
    {
        $ownerKey = $this->createMock(OwnerKeyInterface::class);
        $ownerKey->method('getHash')->willReturn(self::OWNER);

        $specificationFactory = $this->createMock(SpecificationFactory::class);
        $specificationFactory->method('create')->willReturnCallback(function (array $checks): Composite {
            $this->assertSame(['checkout', 'country', 'currency', 'total', 'zero_total'], $checks);

            return $this->specification;
        });

        $logger = $this->createMock(LoggerInterface::class);
        foreach (['error', 'warning', 'info'] as $level) {
            $logger->method($level)->willReturnCallback(
                function (string $message, array $context = []) use ($level): void {
                    $this->logs[] = [$level, $message, $context];
                }
            );
        }

        return new Preparation(
            $this->builder,
            $this->contactBuilder,
            $this->attempts,
            $ownerKey,
            $this->snapshot,
            $this->quoteResource,
            $specificationFactory,
            new GuestCheckout($this->checkoutHelper),
            $this->lockManager,
            $logger,
            $attemptLimit
        );
    }

    /**
     * @return Quote&MockObject
     */
    private function quoteMock(bool $minimumAmountMet = true): Quote
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'hasItems', 'getPayment', 'collectTotals', 'reserveOrderId', 'validateMinimumAmount'])
            ->getMock();
        $quote->method('getId')->willReturn(self::QUOTE_ID);
        $quote->method('hasItems')->willReturn(true);
        $quote->method('getPayment')->willReturnCallback(fn (): Payment => $this->payment);
        $quote->method('validateMinimumAmount')->willReturn($minimumAmountMet);
        $quote->method('collectTotals')->willReturnCallback(function () use ($quote): Quote {
            // Records the flag each collection started with: false forces Magento to collect again.
            $flags = (array) $quote->getData('collected_with_flag');
            $flags[] = $quote->getData('totals_collected_flag');
            $quote->setData('collected_with_flag', $flags);

            return $quote;
        });
        $quote->method('reserveOrderId')->willReturnCallback(function () use ($quote): Quote {
            if ((string) $quote->getData('reserved_order_id') === '') {
                $quote->setData('reserved_order_id', self::RESERVED);
            }

            return $quote;
        });

        return $quote;
    }

    private function rebindPayment(): void
    {
        $this->quote = $this->quoteMock();
        $this->quote->setData(['store_id' => self::STORE_ID]);
    }

    private function expectNoWrites(): void
    {
        $this->quoteResource->expects($this->never())->method('save');
        $this->attempts->expects($this->never())->method('create');
        $this->attempts->expects($this->never())->method('transition');
        $this->builder->expects($this->never())->method('build');
    }

    /**
     * @return array<string, mixed>
     */
    private function checkoutObject(string $mode): array
    {
        return [
            'merchant' => ['public_api_key' => 'PUBLIC'],
            'metadata' => ['mode' => $mode],
            'order_id' => self::RESERVED,
            'total' => self::TOTAL,
            'currency' => 'USD',
        ];
    }

    private function attempt(
        string $attemptId = self::ATTEMPT_ID,
        State $state = State::INITIATED,
        string $reserved = self::RESERVED
    ): Attempt {
        return new Attempt(
            $attemptId,
            self::OWNER,
            self::QUOTE_ID,
            self::STORE_ID,
            null,
            $reserved,
            'USD',
            self::TOTAL,
            'affirm_gateway',
            self::SNAPSHOT,
            $state,
            null,
            null,
            null,
            null,
            null,
            '2026-10-08 10:00:00',
            '2026-10-08 10:00:00',
            '2026-10-08 11:00:00'
        );
    }
}
