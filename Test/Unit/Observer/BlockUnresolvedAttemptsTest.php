<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Test\Unit\Observer;

use Hyva\CheckoutAffirm\Model\Attempt\AttemptRepositoryInterface;
use Hyva\CheckoutAffirm\Model\Attempt\PlacementContext;
use Hyva\CheckoutAffirm\Observer\BlockUnresolvedAttempts;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote\Address;
use Magento\Sales\Api\Data\OrderInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class BlockUnresolvedAttemptsTest extends TestCase
{
    private const ATTEMPT_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const RESERVED = '000000123';

    /**
     * @var AttemptRepositoryInterface&MockObject
     */
    private AttemptRepositoryInterface $attempts;

    /**
     * @var PlacementContext
     */
    private PlacementContext $context;

    /**
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface $logger;

    /**
     * @var BlockUnresolvedAttempts
     */
    private BlockUnresolvedAttempts $guard;

    protected function setUp(): void
    {
        $this->attempts = $this->createMock(AttemptRepositoryInterface::class);
        $this->context = new PlacementContext();
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->guard = new BlockUnresolvedAttempts($this->attempts, $this->context, $this->logger);
    }

    public function testLetsCartsWithoutUnresolvedAttemptsThrough(): void
    {
        $this->attempts->expects($this->once())
            ->method('hasUnresolvedForQuote')
            ->with(42, null)
            ->willReturn(false);

        $this->guard->execute($this->observer(42));
    }

    public function testBlocksAnyPaymentWhileAnAttemptIsUnresolved(): void
    {
        $this->attempts->method('hasUnresolvedForQuote')->with(42, null)->willReturn(true);

        try {
            $this->guard->execute($this->observer(42));
            $this->fail('The submission must be refused.');
        } catch (LocalizedException $exception) {
            $this->assertSame(
                'Payment for this cart is still being confirmed. Please wait a moment or contact us.',
                $exception->getMessage()
            );
        }
    }

    public function testExemptsExactlyThePlacementRunningInThisRequest(): void
    {
        $this->context->begin(self::ATTEMPT_ID, 42);
        $this->attempts->expects($this->exactly(2))
            ->method('hasUnresolvedForQuote')
            ->willReturnCallback(function (int $quoteId, ?string $except): bool {
                // Same quote: only its own attempt is exempt; another quote gets no exemption.
                $this->assertSame($quoteId === 42 ? self::ATTEMPT_ID : null, $except);

                return false;
            });

        $this->guard->execute($this->observer(42));
        $this->guard->execute($this->observer(43));
    }

    public function testOtherUnresolvedAttemptsStillBlockTheRunningPlacement(): void
    {
        $this->context->begin(self::ATTEMPT_ID, 42);
        $this->attempts->method('hasUnresolvedForQuote')->with(42, self::ATTEMPT_ID)->willReturn(true);

        $this->expectException(LocalizedException::class);
        $this->guard->execute($this->observer(42));
    }

    public function testLetsThePipelinesPlacementThroughUnderItsReservedNumber(): void
    {
        $this->context->begin(self::ATTEMPT_ID, 42, self::RESERVED);
        $this->attempts->expects($this->once())
            ->method('hasUnresolvedForQuote')
            ->with(42, self::ATTEMPT_ID)
            ->willReturn(false);
        $this->logger->expects($this->never())->method('warning');

        $this->guard->execute($this->observer(42, self::RESERVED));
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function otherOrderNumbers(): array
    {
        return ['re-reserved number' => ['000000124'], 'no number' => [null]];
    }

    #[DataProvider('otherOrderNumbers')]
    public function testRefusesThePipelinesPlacementUnderAnotherOrderNumber(?string $submitted): void
    {
        // Magento reserved a new number because another order used the attempt's: Affirm's checkout carries the
        // reserved one, so the placement stops before the authorization.
        $this->context->begin(self::ATTEMPT_ID, 42, self::RESERVED);
        $this->attempts->expects($this->never())->method('hasUnresolvedForQuote');
        $warnings = [];
        $this->logger->method('warning')->willReturnCallback(
            function (string $message, array $context = []) use (&$warnings): void {
                $warnings[] = $context;
            }
        );

        try {
            $this->guard->execute($this->observer(42, $submitted ?? false));
            $this->fail('The placement must be refused.');
        } catch (LocalizedException $exception) {
            $this->assertSame(
                'The order number reserved for this Affirm payment is no longer available.'
                . ' Please start the payment again.',
                $exception->getMessage()
            );
        }
        $this->assertSame(
            [['order_reference' => self::RESERVED, 'submitted_order_reference' => (string) $submitted]],
            $warnings
        );
        $this->assertStringNotContainsString(self::ATTEMPT_ID, (string) json_encode($warnings));
    }

    public function testOrderNumberIsCheckedOnlyForThePipelinesOwnCartAndWhenKnown(): void
    {
        $this->attempts->method('hasUnresolvedForQuote')->willReturn(false);
        $this->logger->expects($this->never())->method('warning');

        // Another cart in the same request.
        $this->context->begin(self::ATTEMPT_ID, 42, self::RESERVED);
        $this->guard->execute($this->observer(43, '000000999'));
        $this->context->end();

        // A placement that did not say which number it was confirmed for.
        $this->context->begin(self::ATTEMPT_ID, 42);
        $this->guard->execute($this->observer(42, '000000999'));
    }

    public function testCoversEachMultishippingOrder(): void
    {
        // checkout_type_multishipping_create_orders_single: order, address and quote, before payment.
        $this->attempts->method('hasUnresolvedForQuote')->with(42, null)->willReturn(true);

        $this->expectException(LocalizedException::class);
        $this->guard->execute($this->observer(42, '000000200', ['address' => $this->createMock(Address::class)]));
    }

    public function testIgnoresEventsWithoutAStoredQuote(): void
    {
        $this->attempts->expects($this->never())->method('hasUnresolvedForQuote');

        $this->guard->execute($this->observer(null));
        $this->guard->execute($this->observer(0));
    }

    public function testFailsOpenWhenAttemptsCannotBeRead(): void
    {
        $this->attempts->method('hasUnresolvedForQuote')->willThrowException(new RuntimeException('no table'));
        $this->logger->expects($this->once())->method('error');

        $this->guard->execute($this->observer(42));
    }

    /**
     * An observer for sales_model_service_quote_submit_before (or the multishipping event, with an address).
     *
     * @param int|null $quoteId null: no quote in the event
     * @param string|false|null $orderNumber the order's increment id; null: no order in the event; false: an
     *        order without increment id
     * @param array<string, mixed> $extra further event data
     * @return Observer
     */
    private function observer(?int $quoteId, string|false|null $orderNumber = null, array $extra = []): Observer
    {
        $data = $extra;
        if ($quoteId !== null) {
            $quote = $this->createMock(CartInterface::class);
            $quote->method('getId')->willReturn($quoteId);
            $data['quote'] = $quote;
        }
        if ($orderNumber !== null) {
            $order = $this->createMock(OrderInterface::class);
            $order->method('getIncrementId')->willReturn($orderNumber === false ? null : $orderNumber);
            $data['order'] = $order;
        }

        return new Observer(['event' => new Event($data)]);
    }
}
