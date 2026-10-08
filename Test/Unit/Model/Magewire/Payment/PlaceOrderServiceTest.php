<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Test\Unit\Model\Magewire\Payment;

use Hyva\Checkout\Model\Magewire\Payment\DefaultOrderData;
use Hyva\CheckoutAffirm\Model\Checkout\ConfirmationOutcome;
use Hyva\CheckoutAffirm\Model\Checkout\ConfirmationPipelineInterface;
use Hyva\CheckoutAffirm\Model\Checkout\SuccessSession;
use Hyva\CheckoutAffirm\Model\Magewire\Payment\PlaceOrderService;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Model\Quote;
use Magewirephp\Magewire\Component;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class PlaceOrderServiceTest extends TestCase
{
    private const ATTEMPT_ID = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
    private const TOKEN = 'TOKEN_marker-1234';

    /** @var ConfirmationPipelineInterface&MockObject */
    private ConfirmationPipelineInterface $pipeline;

    /** @var SuccessSession&MockObject */
    private SuccessSession $successSession;

    /** @var CartManagementInterface&MockObject */
    private CartManagementInterface $cartManagement;

    /** @var Quote&MockObject */
    private Quote $quote;

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $errors = [];

    /** @var PlaceOrderService */
    private PlaceOrderService $service;

    protected function setUp(): void
    {
        $this->pipeline = $this->createMock(ConfirmationPipelineInterface::class);
        $this->successSession = $this->createMock(SuccessSession::class);
        $this->cartManagement = $this->createMock(CartManagementInterface::class);
        $this->cartManagement->expects($this->never())->method('placeOrder');
        $this->quote = $this->createMock(Quote::class);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(function (string $message, array $context = []): void {
            $this->errors[] = [$message, $context];
        });

        $this->service = new PlaceOrderService(
            $this->cartManagement,
            $this->pipeline,
            $this->successSession,
            $logger,
            new DefaultOrderData()
        );
    }

    public function testPlacesTheOrderThroughThePipeline(): void
    {
        $this->givenPayment(['checkout_token' => self::TOKEN, 'attempt_id' => self::ATTEMPT_ID, 'other' => 'x']);
        $this->pipeline->expects($this->once())->method('confirm')
            ->with(self::ATTEMPT_ID, self::TOKEN, $this->quote)
            ->willReturn(ConfirmationOutcome::placed(77, '000000123', 42));
        $this->successSession->expects($this->never())->method('armForOutcome');

        $this->assertFalse($this->service->canRedirect());
        $this->assertSame(77, $this->service->placeOrder($this->quote));
        $this->assertTrue($this->service->canRedirect());
        $this->assertTrue($this->service->canPlaceOrder());
    }

    public function testReplayPreparesTheSuccessPage(): void
    {
        $this->givenValidPayment();
        $outcome = ConfirmationOutcome::placed(77, '000000123', 42, true);
        $this->pipeline->method('confirm')->willReturn($outcome);
        $this->successSession->expects($this->once())->method('armForOutcome')->with($outcome)->willReturn(true);

        $this->assertSame(77, $this->service->placeOrder($this->quote));
        $this->assertTrue($this->service->canRedirect());
    }

    public function testReplayStillReturnsTheOrderWhenTheSuccessPageCannotBePrepared(): void
    {
        $this->givenValidPayment();
        $this->pipeline->method('confirm')->willReturn(ConfirmationOutcome::placed(77, '000000123', 42, true));
        $this->successSession->method('armForOutcome')->willThrowException(new RuntimeException('db down'));

        $this->assertSame(77, $this->service->placeOrder($this->quote));
        $this->assertCount(1, $this->errors);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function invalidPayments(): array
    {
        $valid = ['checkout_token' => self::TOKEN, 'attempt_id' => self::ATTEMPT_ID];

        return [
            'nothing stored' => [[]],
            'token missing' => [['attempt_id' => self::ATTEMPT_ID]],
            'attempt missing' => [['checkout_token' => self::TOKEN]],
            'token empty' => [['checkout_token' => ''] + $valid],
            'token not a string' => [['checkout_token' => ['x']] + $valid],
            'token with a path' => [['checkout_token' => 'abc/../def'] + $valid],
            'token too long' => [['checkout_token' => str_repeat('a', 129)] + $valid],
            'attempt id upper case' => [['attempt_id' => strtoupper(self::ATTEMPT_ID)] + $valid],
            'attempt id short' => [['attempt_id' => 'abc'] + $valid],
            'attempt id not a string' => [['attempt_id' => 12] + $valid],
        ];
    }

    /**
     * @param array<string, mixed> $payment
     */
    #[DataProvider('invalidPayments')]
    public function testRefusesMissingOrMalformedData(array $payment): void
    {
        $this->givenPayment($payment);
        $this->pipeline->expects($this->never())->method('confirm');

        $exception = $this->placeAndCatch();

        $this->assertSame(
            'Your Affirm payment could not be confirmed. Please place the order again.',
            $exception->getMessage()
        );
        $this->assertFalse($this->service->canRedirect());
    }

    public function testRefusesAPaymentGroupThatIsNotAnArray(): void
    {
        $this->service->getData()->setData(['payment' => 'checkout_token=' . self::TOKEN]);
        $this->pipeline->expects($this->never())->method('confirm');

        $this->placeAndCatch();
    }

    /**
     * @return array<string, array{0: ConfirmationOutcome, 1: string}>
     */
    public static function unplacedOutcomes(): array
    {
        // Modal mode cannot wait for another placement: the stored token is dropped, so the shopper places again.
        $placeAgain = 'Your Affirm payment could not be completed while another payment for this cart is being'
            . ' processed. Please place the order again in a moment.';

        return [
            'in progress' => [ConfirmationOutcome::inProgress('Your payment is being confirmed.'), $placeAgain],
            'cart locked, retry' => [
                ConfirmationOutcome::inProgress('Being confirmed.', 42, ConfirmationOutcome::REASON_RETRY),
                $placeAgain,
            ],
            'another attempt of the cart is unresolved' => [
                ConfirmationOutcome::failed(ConfirmationOutcome::REASON_IN_PROGRESS, 'Payment is being confirmed.', 42),
                $placeAgain,
            ],
            'unexpected error before the claim' => [
                ConfirmationOutcome::failed(ConfirmationOutcome::REASON_RETRY, 'We could not confirm it. Try again.'),
                'We could not confirm it. Try again.',
            ],
            'uncertain' => [
                ConfirmationOutcome::uncertain('We are confirming your payment for order 000000123.', 42, '000000123'),
                'We are confirming your payment for order 000000123.',
            ],
            'failed' => [
                ConfirmationOutcome::failed('cart_changed', 'Your cart changed. Please start the payment again.'),
                'Your cart changed. Please start the payment again.',
            ],
            'failed without a message' => [
                ConfirmationOutcome::failed('not_found', ''),
                'Your Affirm payment was not completed. Please try again or use another payment method.',
            ],
            'in progress without a message' => [ConfirmationOutcome::inProgress(''), $placeAgain],
            'uncertain without a message' => [
                ConfirmationOutcome::uncertain(''),
                'Your Affirm payment is still being confirmed. Please wait a moment, then try again.',
            ],
        ];
    }

    #[DataProvider('unplacedOutcomes')]
    public function testUnplacedOutcomesBecomeShopperMessages(ConfirmationOutcome $outcome, string $message): void
    {
        $this->givenValidPayment();
        $this->pipeline->method('confirm')->willReturn($outcome);
        $this->successSession->expects($this->never())->method('armForOutcome');

        $exception = $this->placeAndCatch();

        $this->assertSame($message, $exception->getMessage());
        $this->assertNull($exception->getPrevious());
        $this->assertFalse($this->service->canRedirect());
    }

    public function testMessagesWithPercentSignsAreNotReinterpreted(): void
    {
        $this->givenValidPayment();
        $this->pipeline->method('confirm')->willReturn(ConfirmationOutcome::failed('declined', '0% APR %1 offer'));

        $this->assertSame('0% APR %1 offer', $this->placeAndCatch()->getMessage());
    }

    public function testUnexpectedPipelineFailuresNeverLeakTheTokenOrAttempt(): void
    {
        $this->givenValidPayment();
        $this->pipeline->method('confirm')->willThrowException(
            new RuntimeException('GET /api/v2/checkout/' . self::TOKEN . ' for ' . self::ATTEMPT_ID)
        );

        $exception = $this->placeAndCatch();

        $this->assertSame(
            'We could not confirm your Affirm payment. Please wait a moment, then try again.',
            $exception->getMessage()
        );
        $this->assertNull($exception->getPrevious());
        $serialized = (string) json_encode($this->errors);
        $this->assertStringNotContainsString(self::TOKEN, $serialized);
        $this->assertStringNotContainsString(self::ATTEMPT_ID, $serialized);
        $this->assertSame(RuntimeException::class, $this->errors[0][1]['exception']);
    }

    public function testAFailedPlacementResetsAnEarlierSuccess(): void
    {
        $this->givenValidPayment();
        $this->pipeline->method('confirm')->willReturnOnConsecutiveCalls(
            ConfirmationOutcome::placed(77, '000000123', 42),
            ConfirmationOutcome::inProgress('Busy.')
        );

        $this->service->placeOrder($this->quote);
        $this->placeAndCatch();

        $this->assertFalse($this->service->canRedirect());
    }

    public function testHandleExceptionRethrows(): void
    {
        $exception = new LocalizedException(__('Nope.'));

        $this->expectExceptionObject($exception);
        $this->service->handleException($exception, $this->createMock(Component::class), $this->quote);
    }

    private function givenValidPayment(): void
    {
        $this->givenPayment(['checkout_token' => self::TOKEN, 'attempt_id' => self::ATTEMPT_ID]);
    }

    /**
     * @param array<string, mixed> $payment
     */
    private function givenPayment(array $payment): void
    {
        // What Hyvä's processor does with the browser's storage data before placeOrder().
        $this->service->getData()->setData(['payment' => $payment, 'shipping' => []]);
    }

    private function placeAndCatch(): LocalizedException
    {
        try {
            $this->service->placeOrder($this->quote);
        } catch (LocalizedException $exception) {
            $this->assertStringNotContainsString(self::TOKEN, $exception->getMessage());
            $this->assertStringNotContainsString(self::ATTEMPT_ID, $exception->getMessage());

            return $exception;
        }
        $this->fail('No exception was thrown.');
    }
}
