<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Test\Unit\Magewire\Payment\Method;

use Hyva\Checkout\Model\ConfigData\HyvaThemes\Developer\SystemConfigEvaluationApi;
use Hyva\Checkout\Model\Magewire\Component\Evaluation\ErrorEventMessage;
use Hyva\Checkout\Model\Magewire\Component\Evaluation\Success;
use Hyva\Checkout\Model\Magewire\Component\EvaluationResultFactory;
use Hyva\CheckoutAffirm\Magewire\Payment\Method\AffirmGateway;
use Hyva\CheckoutAffirm\Model\Attempt\AttemptRepositoryInterface;
use Hyva\CheckoutAffirm\Model\Attempt\OwnerKeyInterface;
use Hyva\CheckoutAffirm\Model\Attempt\SnapshotInterface;
use Hyva\CheckoutAffirm\Model\Checkout\AccountingException;
use Hyva\CheckoutAffirm\Model\Checkout\CheckoutObjectBuilderInterface;
use Hyva\CheckoutAffirm\Model\Checkout\ContactBuilder;
use Hyva\CheckoutAffirm\Model\Checkout\GuestCheckout;
use Hyva\CheckoutAffirm\Model\Checkout\Preparation;
use Hyva\CheckoutAffirm\ViewModel\Method;
use Magento\Checkout\Helper\Data as CheckoutHelper;
use Magento\Checkout\Model\Session as CheckoutSession;
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
use ReflectionClass;
use RuntimeException;

class AffirmGatewayTest extends TestCase
{
    private const QUOTE_ID = 42;
    private const STORE_ID = 1;
    private const RESERVED = '000000123';
    private const OWNER = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
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

    /** @var AttemptRepositoryInterface&MockObject */
    private AttemptRepositoryInterface $attempts;

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
        $this->attempts = $this->createMock(AttemptRepositoryInterface::class);
        $this->quoteResource = $this->createMock(QuoteResource::class);

        $this->method = $this->createMock(Method::class);
        $this->method->method('isEduEnabled')->willReturn(true);
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

    public function testEvaluationReusesTheAvailabilityOfTheInlineRebuild(): void
    {
        $this->paymentMethod = $this->createMock(MethodInterface::class);
        $this->paymentMethod->expects($this->once())->method('isAvailable')->willReturn(false);
        $this->payment = $this->createMock(Payment::class);
        $this->payment->method('getMethod')->willReturn('affirm_gateway');
        $this->payment->method('getMethodInstance')->willReturn($this->paymentMethod);
        $this->rebindPayment();

        $component = $this->component();
        $component->prepareInline();

        $this->assertInstanceOf(ErrorEventMessage::class, $component->evaluateCompletion($this->resultFactory()));
    }

    public function testMountReadsTheConfiguration(): void
    {
        $component = $this->component();
        $component->mount();

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
        $this->assertSame(['evaluateCompletion', 'mount', 'prepareInline', 'refreshInline'], $own);
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

    public function testRunsAsANativeMagewire3Component(): void
    {
        // Read without instantiating: on Magewire 1 the attribute class does not exist and is never resolved.
        $attributes = (new ReflectionClass(AffirmGateway::class))->getAttributes();

        $this->assertCount(1, $attributes);
        $this->assertSame(
            'Magewirephp\Magewire\Features\SupportMagewireBackwardsCompatibility\HandleBackwardsCompatibility',
            $attributes[0]->getName()
        );
        $this->assertSame(['enabled' => false], $attributes[0]->getArguments());
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
            'inlineCheckout' => null,
            'inlineVersion' => 0,
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

    private function component(): AffirmGateway
    {
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

        $preparation = new Preparation(
            $this->builder,
            $this->createMock(ContactBuilder::class),
            $this->attempts,
            $ownerKey,
            $this->createMock(SnapshotInterface::class),
            $this->quoteResource,
            $specificationFactory,
            new GuestCheckout($this->createMock(CheckoutHelper::class)),
            $this->createMock(LockManagerInterface::class),
            $logger
        );

        return new AffirmGateway($session, $this->builder, $preparation, $this->method, $logger);
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
}
