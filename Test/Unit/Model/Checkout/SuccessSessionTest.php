<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Test\Unit\Model\Checkout;

use Hyva\CheckoutAffirm\Model\Checkout\ConfirmationOutcome;
use Hyva\CheckoutAffirm\Model\Checkout\SuccessSession;
use Magento\Checkout\Model\Session;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SuccessSessionTest extends TestCase
{
    /**
     * @var array<string, mixed>
     */
    private array $fields = [];

    /**
     * @var OrderRepositoryInterface&MockObject
     */
    private OrderRepositoryInterface $orderRepository;

    /**
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface $logger;

    /**
     * @var SuccessSession
     */
    private SuccessSession $successSession;

    protected function setUp(): void
    {
        $session = $this->getMockBuilder(Session::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['__call'])
            ->getMock();
        $session->method('__call')->willReturnCallback(function (string $method, array $args) use ($session) {
            $this->assertStringStartsWith('set', $method);
            $this->fields[substr($method, 3)] = $args[0];

            return $session;
        });
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->successSession = new SuccessSession($session, $this->orderRepository, $this->logger);
    }

    public function testSetsTheFiveSuccessPageFields(): void
    {
        $this->successSession->arm(42, 555, '000000123', 'processing');

        $this->assertSame([
            'LastQuoteId' => 42,
            'LastSuccessQuoteId' => 42,
            'LastOrderId' => 555,
            'LastRealOrderId' => '000000123',
            'LastOrderStatus' => 'processing',
        ], $this->fields);
    }

    public function testArmsFromAnOrder(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn('555');
        $order->method('getIncrementId')->willReturn('000000123');
        $order->method('getStatus')->willReturn('pending');

        $this->successSession->armForOrder(42, $order);

        $this->assertSame(555, $this->fields['LastOrderId']);
        $this->assertSame('000000123', $this->fields['LastRealOrderId']);
        $this->assertSame('pending', $this->fields['LastOrderStatus']);
    }

    public function testArmsForAPlacedOutcomeWithTheOrderStatus(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getStatus')->willReturn('processing');
        $this->orderRepository->expects($this->once())->method('get')->with(555)->willReturn($order);

        $outcome = ConfirmationOutcome::placed(555, '000000123', 42, true);

        $this->assertTrue($this->successSession->armForOutcome($outcome));
        $this->assertSame(42, $this->fields['LastSuccessQuoteId']);
        $this->assertSame('processing', $this->fields['LastOrderStatus']);
    }

    public function testArmsWithoutStatusWhenTheOrderCannotBeLoaded(): void
    {
        $this->orderRepository->method('get')->willThrowException(new NoSuchEntityException());
        $this->logger->expects($this->once())->method('warning');

        $this->assertTrue($this->successSession->armForOutcome(ConfirmationOutcome::placed(555, '000000123', 42)));
        $this->assertSame(555, $this->fields['LastOrderId']);
        $this->assertNull($this->fields['LastOrderStatus']);
    }

    public function testIgnoresOutcomesWithoutAnOrder(): void
    {
        $this->assertFalse($this->successSession->armForOutcome(ConfirmationOutcome::inProgress('wait', 42)));
        $this->assertFalse($this->successSession->armForOutcome(ConfirmationOutcome::uncertain('review', 42, '1')));
        $this->assertFalse($this->successSession->armForOutcome(ConfirmationOutcome::failed('expired', 'gone', 42)));
        $this->assertSame([], $this->fields);
    }
}
