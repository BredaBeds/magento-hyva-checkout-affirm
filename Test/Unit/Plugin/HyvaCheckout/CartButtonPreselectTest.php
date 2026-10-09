<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Test\Unit\Plugin\HyvaCheckout;

use Hyva\Checkout\Magewire\Checkout\Payment\MethodList;
use Hyva\CheckoutAffirm\Plugin\HyvaCheckout\CartButtonPreselect;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Magento\Payment\Model\MethodInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\PaymentInterface;
use Magento\Quote\Api\Data\PaymentInterfaceFactory;
use Magento\Quote\Api\PaymentMethodManagementInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Payment;
use Magewirephp\Magewire\Model\RequestInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CartButtonPreselectTest extends TestCase
{
    private const QUOTE_ID = 497503;
    private const MAGEWIRE3_MARKER = 'Magewirephp\Magewire\Mechanisms\HandleComponents\HandleComponents';

    /**
     * @var array<string, mixed>
     */
    private array $storage = [];

    /**
     * @var CheckoutSession&MockObject
     */
    private CheckoutSession $session;

    /**
     * @var CartRepositoryInterface&MockObject
     */
    private CartRepositoryInterface $cartRepository;

    /**
     * @var PaymentMethodManagementInterface&MockObject
     */
    private PaymentMethodManagementInterface $paymentMethodManagement;

    /**
     * @var PaymentInterface&MockObject
     */
    private PaymentInterface $newPayment;

    /**
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface $logger;

    /**
     * @var Payment&MockObject
     */
    private Payment $quotePayment;

    /**
     * @var MethodList&MockObject
     */
    private MethodList $methodList;

    private CartButtonPreselect $plugin;

    protected function setUp(): void
    {
        $this->session = $this->getMockBuilder(CheckoutSession::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData', 'getQuoteId', 'replaceQuote', '__call'])
            ->getMock();
        $this->session->method('getData')->willReturnCallback(fn ($key = '') => $this->storage[$key] ?? null);
        $this->session->method('getQuoteId')->willReturn(self::QUOTE_ID);
        $this->session->method('__call')->willReturnCallback(function (string $method, array $args) {
            $this->assertSame('unsetData', $method);
            unset($this->storage[$args[0]]);
            return $this->session;
        });

        $this->quotePayment = $this->createMock(Payment::class);
        $quote = $this->createMock(Quote::class);
        $quote->method('getPayment')->willReturn($this->quotePayment);
        $this->cartRepository = $this->createMock(CartRepositoryInterface::class);
        $this->cartRepository->method('get')->with(self::QUOTE_ID)->willReturn($quote);

        $this->paymentMethodManagement = $this->createMock(PaymentMethodManagementInterface::class);
        $this->newPayment = $this->createMock(PaymentInterface::class);
        $paymentFactory = $this->createMock(PaymentInterfaceFactory::class);
        $paymentFactory->method('create')->willReturn($this->newPayment);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->methodList = $this->createMock(MethodList::class);

        $this->plugin = new CartButtonPreselect(
            $this->session,
            $this->cartRepository,
            $this->paymentMethodManagement,
            $paymentFactory,
            $this->logger
        );
    }

    public function testWithoutARecordNothingIsTouched(): void
    {
        $this->cartRepository->expects($this->never())->method('get');
        $this->paymentMethodManagement->expects($this->never())->method('getList');
        $this->paymentMethodManagement->expects($this->never())->method('set');

        $this->plugin->beforeBoot($this->methodList);
    }

    public function testSelectsAffirmOverTheCheckoutDefaultWhenAvailableAndForgetsTheRecord(): void
    {
        $this->givenRecord(self::QUOTE_ID);
        $this->quotePayment->method('getMethod')->willReturn('braintree');
        $this->givenAvailable(['braintree', 'braintree_paypal', 'affirm_gateway']);
        $this->newPayment->expects($this->once())->method('setMethod')->with('affirm_gateway')->willReturnSelf();
        $this->paymentMethodManagement->expects($this->once())->method('set')
            ->with(self::QUOTE_ID, $this->newPayment)->willReturn(1);
        $this->session->expects($this->once())->method('replaceQuote');
        $this->logger->expects($this->never())->method('warning');

        $this->plugin->beforeBoot($this->methodList);

        $this->assertArrayNotHasKey(CartButtonPreselect::SESSION_KEY, $this->storage);
    }

    public function testAcceptsTheRecordAsANumericString(): void
    {
        $this->givenRecord((string) self::QUOTE_ID);
        $this->quotePayment->method('getMethod')->willReturn(null);
        $this->givenAvailable(['affirm_gateway']);
        $this->paymentMethodManagement->expects($this->once())->method('set');

        $this->plugin->beforeBoot($this->methodList);
    }

    public function testAffirmAlreadySelectedOnlyForgetsTheRecord(): void
    {
        $this->givenRecord(self::QUOTE_ID);
        $this->quotePayment->method('getMethod')->willReturn('affirm_gateway');
        $this->paymentMethodManagement->expects($this->never())->method('getList');
        $this->paymentMethodManagement->expects($this->never())->method('set');

        $this->plugin->beforeBoot($this->methodList);

        $this->assertArrayNotHasKey(CartButtonPreselect::SESSION_KEY, $this->storage);
    }

    public function testNeverSelectsAffirmWhenItIsNotAvailableAndKeepsTheRecord(): void
    {
        $this->givenRecord(self::QUOTE_ID);
        $this->quotePayment->method('getMethod')->willReturn('braintree');
        $this->givenAvailable(['braintree', 'braintree_paypal']);
        $this->paymentMethodManagement->expects($this->never())->method('set');
        $this->session->expects($this->never())->method('replaceQuote');

        $this->plugin->beforeBoot($this->methodList);

        $this->assertSame(self::QUOTE_ID, $this->storage[CartButtonPreselect::SESSION_KEY]);
    }

    public function testARecordOfAnotherQuoteIsDropped(): void
    {
        $this->givenRecord(self::QUOTE_ID - 1);
        $this->cartRepository->expects($this->never())->method('get');
        $this->paymentMethodManagement->expects($this->never())->method('set');

        $this->plugin->beforeBoot($this->methodList);

        $this->assertArrayNotHasKey(CartButtonPreselect::SESSION_KEY, $this->storage);
    }

    public function testInvalidRecordsAreIgnored(): void
    {
        $this->cartRepository->expects($this->never())->method('get');
        $this->paymentMethodManagement->expects($this->never())->method('set');

        foreach ([0, -3, 'abc', '', false, []] as $value) {
            $this->storage[CartButtonPreselect::SESSION_KEY] = $value;
            $this->plugin->beforeBoot($this->methodList);
        }
    }

    public function testWaitsDuringAMagewire1UpdateRequestOfTheListItself(): void
    {
        $this->requireMagewire1();
        $this->givenRecord(self::QUOTE_ID);
        $this->givenRequest(true);
        $this->cartRepository->expects($this->never())->method('get');
        $this->paymentMethodManagement->expects($this->never())->method('set');

        $this->plugin->beforeBoot($this->methodList);

        $this->assertSame(self::QUOTE_ID, $this->storage[CartButtonPreselect::SESSION_KEY]);
    }

    public function testAppliesWhenTheListIsRenderedFresh(): void
    {
        // A page load, or Hyvä's step navigation rendering the list inside the main component's update.
        $this->givenRecord(self::QUOTE_ID);
        $this->givenRequest(false);
        $this->quotePayment->method('getMethod')->willReturn('braintree');
        $this->givenAvailable(['braintree', 'affirm_gateway']);
        $this->paymentMethodManagement->expects($this->once())->method('set');

        $this->plugin->beforeBoot($this->methodList);
    }

    public function testFailuresAreLoggedNeverThrownAndForgotten(): void
    {
        $this->givenRecord(self::QUOTE_ID);
        $this->quotePayment->method('getMethod')->willReturn('braintree');
        $this->givenAvailable(['affirm_gateway']);
        $this->paymentMethodManagement->method('set')
            ->willThrowException(new LocalizedException(new Phrase('The requested Payment Method is not available.')));
        $this->session->expects($this->never())->method('replaceQuote');
        $this->logger->expects($this->once())->method('warning')
            ->with($this->stringContains('could not be applied'), $this->arrayHasKey('error'));

        $this->plugin->beforeBoot($this->methodList);

        $this->assertArrayNotHasKey(CartButtonPreselect::SESSION_KEY, $this->storage);
    }

    public function testUnexpectedErrorsDoNotBreakTheList(): void
    {
        $this->givenRecord(self::QUOTE_ID);
        $this->quotePayment->method('getMethod')->willThrowException(new \RuntimeException('db down'));
        $this->logger->expects($this->once())->method('warning');

        $this->plugin->beforeBoot($this->methodList);
    }

    public function testPickingAMethodForgetsTheRecord(): void
    {
        $this->givenRecord(self::QUOTE_ID);

        $this->plugin->beforeUpdatedMethod($this->methodList, 'braintree');

        $this->assertArrayNotHasKey(CartButtonPreselect::SESSION_KEY, $this->storage);

        // A later list build keeps the shopper's choice.
        $this->paymentMethodManagement->expects($this->never())->method('set');
        $this->plugin->beforeBoot($this->methodList);
    }

    public function testPickingAMethodWithoutARecordTouchesNothing(): void
    {
        $this->session->expects($this->never())->method('__call');

        $this->plugin->beforeUpdatedMethod($this->methodList, 'affirm_gateway');
    }

    private function givenRecord(int|string $quoteId): void
    {
        $this->storage[CartButtonPreselect::SESSION_KEY] = $quoteId;
    }

    /**
     * @param list<string> $codes
     */
    private function givenAvailable(array $codes): void
    {
        $methods = [];
        foreach ($codes as $code) {
            $method = $this->createMock(MethodInterface::class);
            $method->method('getCode')->willReturn($code);
            $methods[] = $method;
        }
        $this->paymentMethodManagement->method('getList')->with(self::QUOTE_ID)->willReturn($methods);
    }

    private function givenRequest(bool $subsequent): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('isSubsequent')->willReturn($subsequent);
        $this->methodList->method('getRequest')->willReturn($request);
    }

    private function requireMagewire1(): void
    {
        if (class_exists(self::MAGEWIRE3_MARKER)) {
            $this->markTestSkipped('Magewire 3 hydrates public properties before boot().');
        }
    }
}
