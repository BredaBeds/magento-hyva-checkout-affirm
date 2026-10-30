<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Test\Unit\Magewire\Payment\Method;

use DateTimeInterface;
use Hyva\Checkout\Model\ConfigData\HyvaThemes\Developer\SystemConfigEvaluationApi;
use Hyva\Checkout\Model\Magewire\Component\Evaluation\ErrorEventMessage;
use Hyva\Checkout\Model\Magewire\Component\Evaluation\Success;
use Hyva\Checkout\Model\Magewire\Component\EvaluationResultFactory;
use Hyva\CheckoutAffirm\Magewire\Payment\Method\AffirmGateway;
use Hyva\CheckoutAffirm\Model\Attempt\Attempt;
use Hyva\CheckoutAffirm\Model\Attempt\AttemptRepositoryInterface;
use Hyva\CheckoutAffirm\Model\Attempt\OwnerKeyInterface;
use Hyva\CheckoutAffirm\Model\Attempt\SnapshotInterface;
use Hyva\CheckoutAffirm\Model\Attempt\State;
use Hyva\CheckoutAffirm\Model\Checkout\AccountingException;
use Hyva\CheckoutAffirm\Model\Checkout\CheckoutObjectBuilderInterface;
use Hyva\CheckoutAffirm\Model\Checkout\ContactBuilder;
use Hyva\CheckoutAffirm\ViewModel\Method;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Payment\Model\Checks\Composite;
use Magento\Payment\Model\Checks\SpecificationFactory;
use Magento\Payment\Model\MethodInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Payment;
use Magento\Quote\Model\ResourceModel\Quote as QuoteResource;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;

class AffirmGatewayTest extends TestCase
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

    /** @var Method&MockObject */
    private Method $method;

    /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> */
    private array $logs = [];

    protected function setUp(): void
    {
        $this->paymentMethod = $this->createMock(MethodInterface::class);
        $this->paymentMethod->method('isAvailable')->willReturn(true);

        $this->payment = $this->createMock(Payment::class);
        $this->payment->method('getMethod')->willReturn('affirm_gateway');
        $this->payment->method('getMethodInstance')->willReturn($this->paymentMethod);

        $this->quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'hasItems', 'getPayment', 'collectTotals', 'reserveOrderId'])
            ->getMock();
        $this->quote->method('getId')->willReturn(self::QUOTE_ID);
        $this->quote->method('hasItems')->willReturn(true);
        $this->quote->method('getPayment')->willReturn($this->payment);
        $this->quote->method('collectTotals')->willReturnCallback(function (): Quote {
            // Records the flag each collection started with: false forces Magento to collect again.
            $flags = (array) $this->quote->getData('collected_with_flag');
            $flags[] = $this->quote->getData('totals_collected_flag');
            $this->quote->setData('collected_with_flag', $flags);

            return $this->quote;
        });
        $this->quote->method('reserveOrderId')->willReturnCallback(function (): Quote {
            if ((string) $this->quote->getData('reserved_order_id') === '') {
                $this->quote->setData('reserved_order_id', self::RESERVED);
            }

            return $this->quote;
        });
        $this->quote->setData(['store_id' => self::STORE_ID, 'customer_id' => null]);

        $this->specification = $this->createMock(Composite::class);
        $this->specification->method('isApplicable')->willReturn(true);

        $this->builder = $this->createMock(CheckoutObjectBuilderInterface::class);
        $this->contactBuilder = $this->createMock(ContactBuilder::class);
        $this->attempts = $this->createMock(AttemptRepositoryInterface::class);
        $this->snapshot = $this->createMock(SnapshotInterface::class);
        $this->snapshot->method('hash')->willReturn(self::SNAPSHOT);
        $this->quoteResource = $this->createMock(QuoteResource::class);

        $this->method = $this->createMock(Method::class);
        $this->method->method('getMode')->willReturn('redirect');
        $this->method->method('isEduEnabled')->willReturn(true);
    }

    public function testPrepareCheckoutCreatesAnAttemptAndBuildsTheCheckout(): void
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
            ->willReturn(1);
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

        $component = $this->component();
        $component->checkout = ['stale' => true];
        $component->errorMessage = 'stale';
        $component->prepareCheckout();

        $this->assertNull($component->errorMessage);
        $this->assertSame($checkout, $component->checkout);
        $this->assertSame(self::ATTEMPT_ID, $component->attemptId);
        $this->assertSame('redirect', $component->mode);
        $this->assertSame([false], $this->quote->getData('collected_with_flag'));
    }

    public function testCustomerAttemptsCarryTheCustomerId(): void
    {
        $this->quote->setData('customer_id', '7');
        $this->attempts->method('countRecentForQuote')->willReturn(0);
        $this->attempts->expects($this->once())->method('create')
            ->with(self::QUOTE_ID, self::STORE_ID, 7)
            ->willReturn($this->attempt());
        $this->builder->method('build')->willReturn($this->checkoutObject('modal'));

        $component = $this->component();
        $component->prepareCheckout();

        $this->assertSame(self::ATTEMPT_ID, $component->attemptId);
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

        $component = $this->component('modal');
        $component->prepareCheckout();

        $this->assertNull($component->errorMessage);
        $this->assertSame(self::ATTEMPT_ID, $component->attemptId);
        $this->assertSame('modal', $component->mode);
    }

    public function testDoesNotReuseAnAttemptForAnotherOrderNumber(): void
    {
        $this->quote->setData('reserved_order_id', '000000999');
        $this->attempts->method('findReusable')->willReturn($this->attempt());
        $this->attempts->method('countRecentForQuote')->willReturn(0);
        $newAttempt = $this->attempt(str_repeat('e', 64), State::INITIATED, '000000999');
        $this->attempts->expects($this->once())->method('create')->willReturn($newAttempt);
        $this->builder->method('build')->willReturn($this->checkoutObject('redirect'));

        $component = $this->component();
        $component->prepareCheckout();

        $this->assertSame($newAttempt->attemptId, $component->attemptId);
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

        $component = $this->component();
        $component->prepareCheckout();

        $this->assertSame(
            'Affirm is not available for this cart. Please choose another payment method.',
            $component->errorMessage
        );
        $this->assertNull($component->checkout);
        $this->assertNull($component->attemptId);
    }

    public function testMagentosMethodChecksAreApplied(): void
    {
        $this->specification = $this->createMock(Composite::class);
        $this->specification->method('isApplicable')->willReturn(false);
        $this->expectNoWrites();

        $component = $this->component();
        $component->prepareCheckout();

        $this->assertStringStartsWith('Affirm is not available', (string) $component->errorMessage);
    }

    public function testAnotherSelectedMethodIsRefused(): void
    {
        $this->payment = $this->createMock(Payment::class);
        $this->payment->method('getMethod')->willReturn('checkmo');
        $this->rebindPayment();
        $this->expectNoWrites();

        $component = $this->component();
        $component->prepareCheckout();

        $this->assertSame('Your payment method changed. Please select Affirm again.', $component->errorMessage);
    }

    public function testIncompleteCheckoutDataStopsBeforeTheReservation(): void
    {
        $this->contactBuilder->method('build')
            ->willThrowException(new AccountingException(AccountingException::REASON_MISSING_EMAIL));
        $this->quote->expects($this->never())->method('reserveOrderId');
        $this->expectNoWrites();

        $component = $this->component();
        $component->prepareCheckout();

        $this->assertSame(
            'Please complete your contact, address and shipping details before paying with Affirm.',
            $component->errorMessage
        );
    }

    public function testUnresolvedAttemptBlocksANewOne(): void
    {
        $this->attempts->method('hasUnresolvedForQuote')->with(self::QUOTE_ID)->willReturn(true);
        $this->quote->expects($this->never())->method('reserveOrderId');
        $this->attempts->expects($this->never())->method('create');
        $this->builder->expects($this->never())->method('build');

        $component = $this->component();
        $component->prepareCheckout();

        $this->assertStringContainsString('still being confirmed', (string) $component->errorMessage);
    }

    public function testThrottlesNewAttemptsPerCart(): void
    {
        $this->attempts->method('countRecentForQuote')->willReturn(10);
        $this->attempts->expects($this->never())->method('create');
        $this->attempts->expects($this->never())->method('transition');
        $this->builder->expects($this->never())->method('build');

        $component = $this->component();
        $component->prepareCheckout();

        $this->assertStringStartsWith('Too many Affirm payment attempts', (string) $component->errorMessage);
        $this->assertSame('warning', $this->logs[0][0]);
        $this->assertSame(['quote_id' => self::QUOTE_ID, 'limit' => 10], $this->logs[0][2]);
    }

    public function testTheThrottleIsConfigurable(): void
    {
        $this->attempts->method('countRecentForQuote')->willReturn(10);
        $this->attempts->expects($this->once())->method('create')->willReturn($this->attempt());
        $this->builder->method('build')->willReturn($this->checkoutObject('redirect'));

        $component = $this->component('redirect', 11);
        $component->prepareCheckout();

        $this->assertNull($component->errorMessage);
    }

    public function testDeclinedCartCreatesNoAttempt(): void
    {
        $this->attempts->method('countRecentForQuote')->willReturn(0);
        $this->builder->method('build')
            ->willThrowException(new AccountingException(AccountingException::REASON_UNSUPPORTED_ADJUSTMENT));
        $this->attempts->expects($this->never())->method('create');
        $this->attempts->expects($this->never())->method('transition');

        $component = $this->component();
        $component->prepareCheckout();

        $this->assertSame('Affirm is not available for this cart.', $component->errorMessage);
        $this->assertNull($component->checkout);
    }

    public function testDeclinedReusedAttemptReportsTheBuilderMessage(): void
    {
        $this->quote->setData('reserved_order_id', self::RESERVED);
        $this->attempts->method('findReusable')->willReturn($this->attempt());
        $this->builder->method('build')
            ->willThrowException(new AccountingException(AccountingException::REASON_UNSUPPORTED_CURRENCY));

        $component = $this->component();
        $component->prepareCheckout();

        $this->assertSame('Affirm is not available for the currency of this cart.', $component->errorMessage);
        $this->assertNull($component->attemptId);
    }

    public function testUnexpectedFailuresAreLoggedWithoutTheirMessage(): void
    {
        $this->snapshot = $this->createMock(SnapshotInterface::class);
        $this->snapshot->method('hash')->willThrowException(new RuntimeException('customer ada@example.com'));

        $component = $this->component();
        $component->prepareCheckout();

        $this->assertSame(
            'Affirm is not available right now. Please try again or choose another payment method.',
            $component->errorMessage
        );
        $this->assertSame('error', $this->logs[0][0]);
        $this->assertSame(RuntimeException::class, $this->logs[0][2]['exception']);
        $this->assertStringNotContainsString('ada@example.com', (string) json_encode($this->logs));
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

        $component = $this->component();
        $component->prepareCheckout();

        $this->assertStringStartsWith('Your cart changed', (string) $component->errorMessage);
        $this->assertNull($component->checkout);
        $this->assertNull($component->attemptId);
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

        $component = $this->component();
        $component->prepareCheckout();

        $this->assertSame('Your cart is empty or has expired. Please reload the page.', $component->errorMessage);
    }

    public function testEvaluationPassesWhileAffirmIsAvailable(): void
    {
        $result = $this->component()->evaluateCompletion($this->resultFactory());

        $this->assertInstanceOf(Success::class, $result);
    }

    public function testEvaluationBlocksWhenAffirmIsNotAvailable(): void
    {
        $this->specification = $this->createMock(Composite::class);
        $this->specification->method('isApplicable')->willReturn(false);

        $result = $this->component()->evaluateCompletion($this->resultFactory());

        $this->assertInstanceOf(ErrorEventMessage::class, $result);
        $this->assertSame('payment:method:error', $result->getEvent());
        $this->assertSame(
            'Affirm is not available for this cart. Please choose another payment method.',
            $result->getMessage()
        );
    }

    public function testEvaluationIgnoresAnEarlierErrorMessage(): void
    {
        $component = $this->component();
        $component->errorMessage = 'Too many Affirm payment attempts for this cart.';

        $this->assertInstanceOf(Success::class, $component->evaluateCompletion($this->resultFactory()));
    }

    public function testEvaluationLeavesOtherMethodsAlone(): void
    {
        $this->payment = $this->createMock(Payment::class);
        $this->payment->method('getMethod')->willReturn('checkmo');
        $this->payment->expects($this->never())->method('getMethodInstance');
        $this->rebindPayment();

        $this->assertInstanceOf(Success::class, $this->component()->evaluateCompletion($this->resultFactory()));
    }

    public function testEvaluationFailsClosed(): void
    {
        $this->payment = $this->createMock(Payment::class);
        $this->payment->method('getMethod')->willReturn('affirm_gateway');
        $this->payment->method('getMethodInstance')->willThrowException(new RuntimeException('boom'));
        $this->rebindPayment();

        $result = $this->component()->evaluateCompletion($this->resultFactory());

        $this->assertInstanceOf(ErrorEventMessage::class, $result);
        $this->assertSame('error', $this->logs[0][0]);
    }

    public function testEvaluationReusesTheAvailabilityOfThePreparation(): void
    {
        $this->paymentMethod = $this->createMock(MethodInterface::class);
        $this->paymentMethod->expects($this->once())->method('isAvailable')->willReturn(false);
        $this->payment = $this->createMock(Payment::class);
        $this->payment->method('getMethod')->willReturn('affirm_gateway');
        $this->payment->method('getMethodInstance')->willReturn($this->paymentMethod);
        $this->rebindPayment();

        $component = $this->component();
        $component->prepareCheckout();

        $this->assertInstanceOf(ErrorEventMessage::class, $component->evaluateCompletion($this->resultFactory()));
    }

    public function testMountReadsTheConfiguration(): void
    {
        $component = $this->component();
        $component->mount();

        $this->assertSame('redirect', $component->mode);
        $this->assertTrue($component->edu);
    }

    public function testRefreshInlineRebuildsWithoutSideEffects(): void
    {
        $this->quote->setData('reserved_order_id', self::RESERVED);
        $this->quote->expects($this->never())->method('reserveOrderId');
        $this->quoteResource->expects($this->never())->method('save');
        $this->expectNoAttemptAccess();
        $inline = $this->checkoutObject('inline');
        $this->builder->expects($this->exactly(2))->method('build')->with($this->quote, 'inline', '')
            ->willReturn($inline);

        $component = $this->component();
        // Magewire 1 passes the event payload as one array, Magewire 3 as named arguments.
        $component->refreshInline(['method' => 'affirm_gateway']);
        $component->refreshInline(method: 'affirm_gateway');

        $this->assertSame($inline, $component->inlineCheckout);
        $this->assertSame(2, $component->inlineVersion);
        $this->assertTrue($component->edu);
    }

    public function testInlineVersionSurvivesAnyValueTheBrowserSends(): void
    {
        $this->quote->setData('reserved_order_id', self::RESERVED);
        $this->builder->method('build')->willReturn($this->checkoutObject('inline'));

        $component = $this->component();
        foreach ([PHP_INT_MAX => 1, -5 => 1, 41 => 42] as $sent => $next) {
            $component->inlineVersion = $sent;
            $component->refreshInline();
            $this->assertSame($next, $component->inlineVersion);
        }
    }

    public function testRefreshInlineWaitsForTheFirstPreparation(): void
    {
        $this->quote->expects($this->never())->method('reserveOrderId');
        $this->builder->expects($this->never())->method('build');
        $this->expectNoAttemptAccess();

        $component = $this->component();
        $component->refreshInline();

        $this->assertNull($component->inlineCheckout);
        $this->assertSame(0, $component->inlineVersion);
    }

    public function testRefreshInlineRemovesTheWidgetWhenAffirmCannotBeOffered(): void
    {
        $this->quote->setData('reserved_order_id', self::RESERVED);
        $this->builder->method('build')
            ->willThrowException(new AccountingException(AccountingException::REASON_UNSUPPORTED_ADJUSTMENT));

        $component = $this->component();
        $component->inlineCheckout = ['total' => 1];
        $component->inlineVersion = 4;
        $component->refreshInline();

        $this->assertNull($component->inlineCheckout);
        $this->assertSame(5, $component->inlineVersion);
        $this->assertSame([], $this->logs, 'declines are logged by the builder');
    }

    public function testInlineWidgetOffClearsItsObject(): void
    {
        $this->method = $this->createMock(Method::class);
        $this->method->method('isEduEnabled')->willReturn(false);
        $this->builder->expects($this->never())->method('build');

        $component = $this->component();
        $component->inlineCheckout = ['total' => 1];
        $component->inlineVersion = 2;
        $component->refreshInline();

        $this->assertNull($component->inlineCheckout);
        $this->assertSame(3, $component->inlineVersion);
        $this->assertFalse($component->edu);

        $component->prepareInline();
        $this->assertSame(3, $component->inlineVersion, 'nothing to clear twice');
    }

    public function testPrepareInlineReservesTheOrderNumberButCreatesNoAttempt(): void
    {
        $this->expectNoAttemptAccess();
        $this->quoteResource->expects($this->once())->method('save')->with($this->quote);
        $inline = $this->checkoutObject('inline');
        $this->builder->expects($this->once())->method('build')->with($this->quote, 'inline', '')->willReturn($inline);

        $component = $this->component();
        $component->prepareInline();

        $this->assertSame(self::RESERVED, $this->quote->getData('reserved_order_id'));
        $this->assertSame($inline, $component->inlineCheckout);
        $this->assertSame(1, $component->inlineVersion);
    }

    public function testPrepareInlineHidesTheWidgetWhenAffirmIsUnavailable(): void
    {
        $this->specification = $this->createMock(Composite::class);
        $this->specification->method('isApplicable')->willReturn(false);
        $this->builder->expects($this->never())->method('build');
        $this->quote->expects($this->never())->method('reserveOrderId');

        $component = $this->component();
        $component->inlineCheckout = ['total' => 1];
        $component->prepareInline();

        $this->assertNull($component->inlineCheckout);
        $this->assertSame(1, $component->inlineVersion);
    }

    public function testPrepareInlineIgnoresOtherMethods(): void
    {
        $this->payment = $this->createMock(Payment::class);
        $this->payment->method('getMethod')->willReturn('checkmo');
        $this->rebindPayment();
        $this->builder->expects($this->never())->method('build');

        $component = $this->component();
        $component->prepareInline();

        $this->assertSame(0, $component->inlineVersion);
    }

    public function testBrowserCallableSurface(): void
    {
        $class = new ReflectionClass(AffirmGateway::class);
        $own = [];
        foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $publicMethod) {
            if ($publicMethod->getDeclaringClass()->getName() === AffirmGateway::class
                && !$publicMethod->isConstructor()
            ) {
                $own[] = $publicMethod->getName();
            }
        }
        sort($own);
        $this->assertSame(['evaluateCompletion', 'mount', 'prepareCheckout', 'prepareInline', 'refreshInline'], $own);
        $this->assertTrue($class->getMethod('refreshInline')->isVariadic());

        $component = $this->component();
        $this->assertContains('evaluateCompletion', $this->property($component, 'uncallables'));
        $listeners = $this->property($component, 'listeners');
        $this->assertSame([
            'shipping_method_selected',
            'shipping_address_saved',
            'shipping_address_activated',
            'billing_address_saved',
            'billing_address_activated',
            'coupon_code_applied',
            'coupon_code_revoked',
            'payment_method_selected',
        ], array_keys($listeners));
        $this->assertSame(['refreshInline'], array_values(array_unique($listeners)));
    }

    public function testPublicStateDefaults(): void
    {
        $component = $this->component();
        $state = [];
        foreach ((new ReflectionClass($component))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->getDeclaringClass()->getName() === AffirmGateway::class) {
                $state[$property->getName()] = $property->getValue($component);
            }
        }

        $this->assertSame([
            'checkout' => null,
            'attemptId' => null,
            'errorMessage' => null,
            'inlineCheckout' => null,
            'inlineVersion' => 0,
            'mode' => 'modal',
            'edu' => false,
        ], $state);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function property(AffirmGateway $component, string $name): array
    {
        $value = (new \ReflectionProperty(AffirmGateway::class, $name))->getValue($component);
        $this->assertIsArray($value);

        return $value;
    }

    private function component(string $mode = 'redirect', int $attemptLimit = 10): AffirmGateway
    {
        if ($mode !== 'redirect') {
            $this->method = $this->createMock(Method::class);
            $this->method->method('getMode')->willReturn($mode);
            $this->method->method('isEduEnabled')->willReturn(true);
        }

        $session = $this->createMock(CheckoutSession::class);
        $session->method('getQuote')->willReturn($this->quote);

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

        return new AffirmGateway(
            $session,
            $this->builder,
            $this->contactBuilder,
            $this->attempts,
            $ownerKey,
            $this->snapshot,
            $this->quoteResource,
            $specificationFactory,
            $this->method,
            $logger,
            $attemptLimit
        );
    }

    private function rebindPayment(): void
    {
        $this->quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'hasItems', 'getPayment', 'collectTotals', 'reserveOrderId'])
            ->getMock();
        $this->quote->method('getId')->willReturn(self::QUOTE_ID);
        $this->quote->method('hasItems')->willReturn(true);
        $this->quote->method('getPayment')->willReturn($this->payment);
        $this->quote->method('collectTotals')->willReturnSelf();
        $this->quote->setData(['store_id' => self::STORE_ID]);
    }

    private function expectNoWrites(): void
    {
        $this->quoteResource->expects($this->never())->method('save');
        $this->attempts->expects($this->never())->method('create');
        $this->attempts->expects($this->never())->method('transition');
        $this->builder->expects($this->never())->method('build');
    }

    private function expectNoAttemptAccess(): void
    {
        foreach (['create', 'transition', 'findReusable', 'countRecentForQuote', 'hasUnresolvedForQuote'] as $call) {
            $this->attempts->expects($this->never())->method($call);
        }
    }

    private function resultFactory(): EvaluationResultFactory
    {
        $factory = $this->createMock(EvaluationResultFactory::class);
        $factory->method('createSuccess')->willReturnCallback(static fn (): Success => new Success());
        $factory->method('createErrorMessageEvent')->willReturnCallback(
            fn (): ErrorEventMessage => new ErrorEventMessage($this->createMock(SystemConfigEvaluationApi::class))
        );

        return $factory;
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
