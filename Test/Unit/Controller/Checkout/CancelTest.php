<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Test\Unit\Controller\Checkout;

use Hyva\CheckoutAffirm\Controller\Checkout\Cancel;
use Hyva\CheckoutAffirm\Model\Attempt\Attempt;
use Hyva\CheckoutAffirm\Model\Attempt\AttemptRepositoryInterface;
use Hyva\CheckoutAffirm\Model\Attempt\Ownership;
use Hyva\CheckoutAffirm\Model\Attempt\State;
use Hyva\CheckoutAffirm\Model\Checkout\PaymentStep;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class CancelTest extends TestCase
{
    private const ATTEMPT_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /**
     * @var array<string, mixed>
     */
    private array $params = [];

    /**
     * @var bool
     */
    private bool $owner = true;

    /**
     * @var list<array{0: string, 1: string}>
     */
    private array $messages = [];

    /**
     * @var array<string, string>
     */
    private array $headers = [];

    /**
     * @var array<int, mixed>
     */
    private array $path = [];

    /**
     * @var AttemptRepositoryInterface&MockObject
     */
    private AttemptRepositoryInterface $attempts;

    /**
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface $logger;

    /**
     * @var Cancel
     */
    private Cancel $controller;

    protected function setUp(): void
    {
        $this->messages = [];
        $this->headers = [];
        $this->path = [];
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(fn (string $name) => $this->params[$name] ?? null);

        $redirect = $this->getMockBuilder(Redirect::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['setPath', 'setHeader'])
            ->getMock();
        $redirect->method('setPath')->willReturnCallback(function (string $path, array $params) use ($redirect) {
            $this->path = [$path, $params];

            return $redirect;
        });
        $redirect->method('setHeader')->willReturnCallback(function (string $name, string $value) use ($redirect) {
            $this->headers[$name] = $value;

            return $redirect;
        });
        $redirectFactory = $this->createMock(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($redirect);

        $messages = $this->createMock(ManagerInterface::class);
        $types = ['addNoticeMessage' => 'notice', 'addWarningMessage' => 'warning', 'addErrorMessage' => 'error'];
        foreach ($types as $method => $type) {
            $messages->method($method)->willReturnCallback(function ($text) use ($type, $messages) {
                $this->messages[] = [$type, (string) $text];

                return $messages;
            });
        }

        $this->attempts = $this->createMock(AttemptRepositoryInterface::class);
        $ownership = $this->createMock(Ownership::class);
        $ownership->method('isOwner')->willReturnCallback(fn (): bool => $this->owner);
        $paymentStep = $this->createMock(PaymentStep::class);
        $paymentStep->method('applyTo')->willReturnCallback(function (Redirect $passed): Redirect {
            return $passed->setPath('checkout', ['_query' => ['step' => 'payment']]);
        });
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->controller = new Cancel(
            $request,
            $redirectFactory,
            $messages,
            $this->attempts,
            $ownership,
            $paymentStep,
            $this->logger
        );
    }

    public function testCancelsTheOwnersOpenAttempt(): void
    {
        foreach ([State::INITIATED, State::CONFIRMED] as $state) {
            $this->setUp();
            $this->params = ['attempt' => self::ATTEMPT_ID];
            $this->attempts->method('get')->willReturn($this->attempt($state));
            $this->attempts->expects($this->once())
                ->method('transition')
                ->with(self::ATTEMPT_ID, State::open(), State::FAILED, ['failure_reason' => 'cancelled'])
                ->willReturn(true);

            $this->controller->execute();

            $this->assertSame(
                [['notice', 'The Affirm payment was cancelled. Please choose a payment method to continue.']],
                $this->messages
            );
            $this->assertBackToPaymentStep();
        }
    }

    public function testRecordsADecline(): void
    {
        $this->params = ['attempt' => self::ATTEMPT_ID, 'reason' => 'declined'];
        $this->attempts->method('get')->willReturn($this->attempt(State::INITIATED));
        $this->attempts->expects($this->once())
            ->method('transition')
            ->with(self::ATTEMPT_ID, State::open(), State::FAILED, ['failure_reason' => 'declined'])
            ->willReturn(true);

        $this->controller->execute();

        $this->assertSame('warning', $this->messages[0][0]);
        $this->assertBackToPaymentStep();
    }

    public function testLeavesOtherPeoplesAttemptsAlone(): void
    {
        $this->owner = false;
        $this->params = ['attempt' => self::ATTEMPT_ID];
        $this->attempts->method('get')->willReturn($this->attempt(State::INITIATED));
        $this->attempts->expects($this->never())->method('transition');

        $this->controller->execute();

        $this->assertSame('notice', $this->messages[0][0]);
        $this->assertBackToPaymentStep();
    }

    public function testUnknownOrMissingAttemptsOnlyRedirect(): void
    {
        $this->attempts->expects($this->once())->method('get')->willReturn(null);
        $this->attempts->expects($this->never())->method('transition');

        $this->params = ['attempt' => self::ATTEMPT_ID];
        $this->controller->execute();
        $this->params = [];
        $this->controller->execute();

        $this->assertCount(2, $this->messages);
        $this->assertBackToPaymentStep();
    }

    public function testNeverCancelsAnAttemptThatMayHoldAnAuthorization(): void
    {
        foreach ([State::PLACING, State::AUTHORIZING, State::UNCERTAIN] as $state) {
            $this->setUp();
            $this->params = ['attempt' => self::ATTEMPT_ID];
            $this->attempts->method('get')->willReturn($this->attempt($state));
            $this->attempts->expects($this->never())->method('transition');

            $this->controller->execute();

            $this->assertSame(
                [['notice', 'Payment for this cart is still being confirmed. Please wait a moment or contact us.']],
                $this->messages,
                $state->value
            );
        }
    }

    public function testExplainsAnAlreadyPlacedAttempt(): void
    {
        $this->params = ['attempt' => self::ATTEMPT_ID];
        $this->attempts->method('get')->willReturn($this->attempt(State::PLACED));
        $this->attempts->expects($this->never())->method('transition');

        $this->controller->execute();

        $this->assertStringContainsString('already complete', $this->messages[0][1]);
    }

    public function testReportsTheStateWhenTheCancellationLostARace(): void
    {
        $this->params = ['attempt' => self::ATTEMPT_ID];
        $this->attempts->method('get')->willReturnOnConsecutiveCalls(
            $this->attempt(State::CONFIRMED),
            $this->attempt(State::PLACING)
        );
        $this->attempts->method('transition')->willReturn(false);

        $this->controller->execute();

        $this->assertStringContainsString('still being confirmed', $this->messages[0][1]);
    }

    public function testStorageErrorsStillLeadBackToTheCheckout(): void
    {
        $this->params = ['attempt' => self::ATTEMPT_ID];
        $this->attempts->method('get')->willThrowException(new RuntimeException('no table'));
        $this->logger->expects($this->once())->method('error');

        $this->controller->execute();

        $this->assertSame('notice', $this->messages[0][0]);
        $this->assertBackToPaymentStep();
    }

    /**
     * Assert the redirect to the payment step without caching.
     *
     * @return void
     */
    private function assertBackToPaymentStep(): void
    {
        $this->assertSame(['checkout', ['_query' => ['step' => 'payment']]], $this->path);
        $this->assertSame('no-store, no-cache, must-revalidate, max-age=0', $this->headers['Cache-Control'] ?? null);
    }

    /**
     * An attempt in a state.
     *
     * @param State $state
     * @return Attempt
     */
    private function attempt(State $state): Attempt
    {
        return Attempt::fromRow([
            'attempt_id' => self::ATTEMPT_ID,
            'owner_key_hash' => hash('sha256', 'owner'),
            'quote_id' => 42,
            'store_id' => 1,
            'reserved_increment_id' => '000000123',
            'currency' => 'USD',
            'grand_total_cents' => 100000,
            'snapshot_hash' => hash('sha256', 'cart'),
            'state' => $state->value,
            'expires_at' => '2030-01-01 00:00:00',
        ]);
    }
}
