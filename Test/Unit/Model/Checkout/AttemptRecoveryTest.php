<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Test\Unit\Model\Checkout;

use Hyva\CheckoutAffirm\Model\Attempt\AdminNotifier;
use Hyva\CheckoutAffirm\Model\Attempt\Attempt;
use Hyva\CheckoutAffirm\Model\Attempt\AttemptRepositoryInterface;
use Hyva\CheckoutAffirm\Model\Attempt\StaleAttemptPolicy;
use Hyva\CheckoutAffirm\Model\Attempt\State;
use Hyva\CheckoutAffirm\Model\Checkout\AttemptRecovery;
use Hyva\CheckoutAffirm\Model\Checkout\OrderPlacement;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class AttemptRecoveryTest extends TestCase
{
    private const NOW = 1791367200; // 2026-10-07 10:00:00 UTC
    private const ATTEMPT_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const RESERVED = '000000123';
    private const STALE = '2026-10-07 09:40:00';

    /**
     * @var OrderPlacement&MockObject
     */
    private OrderPlacement $orderPlacement;

    /**
     * @var OrderInterface|null what the lookup by reserved number returns
     */
    private ?OrderInterface $found = null;

    /**
     * @var list<array<int, mixed>> transition() calls: id, from states, to state, set, conditions
     */
    private array $transitions = [];

    /**
     * @var bool
     */
    private bool $transitionResult = true;

    /**
     * @var list<string>
     */
    private array $notifications = [];

    /**
     * @var list<int>
     */
    private array $deactivated = [];

    /**
     * @var list<array{0: string, 1: string, 2: array<string, mixed>}>
     */
    private array $logs = [];

    /**
     * @var AttemptRecovery
     */
    private AttemptRecovery $recovery;

    protected function setUp(): void
    {
        $attempts = $this->createMock(AttemptRepositoryInterface::class);
        $attempts->method('transition')->willReturnCallback(
            function (string $id, array $from, State|string $to, array $set = [], array $conditions = []): bool {
                $this->transitions[] = [$id, $from, $to, $set, $conditions];

                return $this->transitionResult;
            }
        );

        $this->orderPlacement = $this->createMock(OrderPlacement::class);
        $this->orderPlacement->method('findOrder')->willReturnCallback(
            function (string $incrementId, int $storeId): ?OrderInterface {
                $this->assertSame([self::RESERVED, 1], [$incrementId, $storeId]);

                return $this->found;
            }
        );

        $notifier = $this->createMock(AdminNotifier::class);
        $notifier->method('notifyUncertain')->willReturnCallback(function (string $reference): void {
            $this->notifications[] = $reference;
        });

        $logger = $this->createMock(LoggerInterface::class);
        foreach (['critical', 'error', 'warning', 'info'] as $level) {
            $logger->method($level)->willReturnCallback(
                function (string $message, array $context = []) use ($level): void {
                    $this->logs[] = [$level, $message, $context];
                }
            );
        }

        $this->recovery = new AttemptRecovery(
            $attempts,
            $this->orderPlacement,
            $notifier,
            new StaleAttemptPolicy(),
            $logger
        );
    }

    protected function tearDown(): void
    {
        $this->assertStringNotContainsString(self::ATTEMPT_ID, (string) json_encode($this->logs));
    }

    public function testFindsTheOrderPlacedForTheAttempt(): void
    {
        $this->found = $this->order();

        $this->assertSame($this->found, $this->recovery->findOrder($this->attempt(State::AUTHORIZING)));
        $this->assertSame([], $this->logs);
    }

    public function testNoOrderOrAnUnsavedOrderIsNoOrder(): void
    {
        $attempt = $this->attempt(State::AUTHORIZING);
        $this->assertNull($this->recovery->findOrder($attempt));

        $this->found = $this->order(['entity_id' => null]);
        $this->assertNull($this->recovery->findOrder($attempt));
        $this->assertSame([], $this->logs);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function foreignOrders(): array
    {
        return [
            'another cart' => [['quote_id' => 43]],
            'no cart' => [['quote_id' => null]],
            'another payment method' => [['method' => 'checkmo']],
            'another attempt' => [['attempt_id' => str_repeat('b', 64)]],
            'no attempt id' => [['attempt_id' => null]],
            'no payment' => [['payment' => false]],
        ];
    }

    /**
     * @param array<string, mixed> $origin
     */
    #[DataProvider('foreignOrders')]
    public function testOrderOfAnotherPlacementIsNotTheAttemptsOrder(array $origin): void
    {
        $this->found = $this->order($origin + ['increment_id' => self::RESERVED]);
        $attempt = $this->attempt(State::AUTHORIZING);

        $this->assertFalse($this->recovery->belongsTo($this->found, $attempt));
        $this->assertNull($this->recovery->findOrder($attempt));
        $this->assertCount(1, $this->logs);
        $this->assertSame('warning', $this->logs[0][0]);
        $this->assertSame(['order_reference' => self::RESERVED], $this->logs[0][2]);
    }

    public function testMarkUncertainAlertsOnlyWhenItMovedTheAttempt(): void
    {
        $attempt = $this->attempt(State::AUTHORIZING);

        $this->assertTrue($this->recovery->markUncertain($attempt, [State::AUTHORIZING]));
        $this->transitionResult = false;
        $this->assertFalse($this->recovery->markUncertain($attempt, [State::AUTHORIZING]));

        $this->assertSame(
            [
                [self::ATTEMPT_ID, [State::AUTHORIZING], State::UNCERTAIN, [], []],
                [self::ATTEMPT_ID, [State::AUTHORIZING], State::UNCERTAIN, [], []],
            ],
            $this->transitions
        );
        $this->assertSame([self::RESERVED], $this->notifications);
        $this->assertSame(
            [[
                'critical',
                'Affirm checkout: Affirm may hold an authorization without an order; the attempt needs review.',
                ['order_reference' => self::RESERVED],
            ]],
            $this->logs
        );
    }

    public function testFreshPlacementsAreNeverTouched(): void
    {
        $this->orderPlacement->expects($this->never())->method('findOrder');

        foreach ([State::PLACING, State::AUTHORIZING] as $state) {
            $attempt = $this->attempt($state, '2026-10-07 09:50:00');

            $this->assertSame(AttemptRecovery::ESCALATION_SKIPPED, $this->recovery->escalate($attempt, self::NOW));
        }
        $this->assertSame([], $this->transitions);
    }

    public function testOnlyPlacementsAreEscalated(): void
    {
        foreach ([State::INITIATED, State::CONFIRMED, State::UNCERTAIN, State::PLACED, State::FAILED] as $state) {
            $this->assertSame(
                AttemptRecovery::ESCALATION_SKIPPED,
                $this->recovery->escalate($this->attempt($state), self::NOW)
            );
        }
        $this->assertSame([], $this->transitions);
    }

    /**
     * @return array<string, array{0: State}>
     */
    public static function placementStates(): array
    {
        return ['placing' => [State::PLACING], 'authorizing' => [State::AUTHORIZING]];
    }

    #[DataProvider('placementStates')]
    public function testAbandonedPlacementWithItsOrderIsPlaced(State $state): void
    {
        $this->found = $this->order(['increment_id' => '000000124']);
        $this->orderPlacement->method('deactivateQuoteById')->willReturnCallback(function (int $quoteId): bool {
            $this->deactivated[] = $quoteId;

            return true;
        });

        $result = $this->recovery->escalate($this->attempt($state), self::NOW);

        $this->assertSame(AttemptRecovery::ESCALATED_PLACED, $result);
        $this->assertSame(
            [[
                self::ATTEMPT_ID,
                [$state],
                State::PLACED,
                ['order_increment_id' => '000000124', 'order_id' => 555],
                ['updated_at' => self::STALE],
            ]],
            $this->transitions
        );
        $this->assertSame([42], $this->deactivated);
        $this->assertSame([], $this->notifications);
        $this->assertSame([['warning', 'order_reference' => '000000124']], $this->levelsAndReferences());
    }

    #[DataProvider('placementStates')]
    public function testAbandonedPlacementWithoutItsOrderBecomesUncertain(State $state): void
    {
        $this->orderPlacement->expects($this->never())->method('deactivateQuoteById');

        $result = $this->recovery->escalate($this->attempt($state), self::NOW);

        $this->assertSame(AttemptRecovery::ESCALATED_UNCERTAIN, $result);
        $this->assertSame(
            [[self::ATTEMPT_ID, [$state], State::UNCERTAIN, [], ['updated_at' => self::STALE]]],
            $this->transitions
        );
        $this->assertSame([self::RESERVED], $this->notifications);
        $this->assertSame([['critical', 'order_reference' => self::RESERVED]], $this->levelsAndReferences());
    }

    public function testAbandonedPlacementWithAForeignOrderBecomesUncertain(): void
    {
        $this->found = $this->order(['method' => 'braintree']);
        $this->orderPlacement->expects($this->never())->method('deactivateQuoteById');

        $result = $this->recovery->escalate($this->attempt(State::PLACING), self::NOW);

        $this->assertSame(AttemptRecovery::ESCALATED_UNCERTAIN, $result);
        $this->assertSame(State::UNCERTAIN, $this->transitions[0][2]);
        $this->assertSame([self::RESERVED], $this->notifications);
        $this->assertSame(
            [['warning', 'order_reference' => self::RESERVED], ['critical', 'order_reference' => self::RESERVED]],
            $this->levelsAndReferences()
        );
    }

    public function testAttemptThatChangedSinceItWasReadIsLeftAlone(): void
    {
        $this->transitionResult = false;
        $this->orderPlacement->expects($this->never())->method('deactivateQuoteById');

        $this->assertSame(
            AttemptRecovery::ESCALATION_SKIPPED,
            $this->recovery->escalate($this->attempt(State::AUTHORIZING), self::NOW)
        );
        $this->found = $this->order();
        $this->assertSame(
            AttemptRecovery::ESCALATION_SKIPPED,
            $this->recovery->escalate($this->attempt(State::AUTHORIZING), self::NOW)
        );

        $this->assertCount(2, $this->transitions);
        $this->assertSame([], $this->notifications);
        $this->assertSame([], $this->logs);
    }

    public function testCartThatCannotBeDeactivatedDoesNotUndoThePlacedAttempt(): void
    {
        $this->found = $this->order();
        $this->orderPlacement->method('deactivateQuoteById')
            ->willThrowException(new RuntimeException('lock wait timeout for ' . self::ATTEMPT_ID));

        $this->assertSame(
            AttemptRecovery::ESCALATED_PLACED,
            $this->recovery->escalate($this->attempt(State::AUTHORIZING), self::NOW)
        );
        $this->assertSame(['error', 'warning'], array_column($this->logs, 0));
        $this->assertSame(
            ['exception_class' => RuntimeException::class, 'order_reference' => self::RESERVED],
            $this->logs[0][2]
        );
    }

    /**
     * Logged levels with their order reference.
     *
     * @return list<array<int|string, string>>
     */
    private function levelsAndReferences(): array
    {
        return array_map(
            static fn (array $log): array => [$log[0], 'order_reference' => (string) $log[2]['order_reference']],
            $this->logs
        );
    }

    /**
     * An attempt of quote 42 in store 1, last changed at $updatedAt.
     *
     * @param State $state
     * @param string $updatedAt
     * @return Attempt
     */
    private function attempt(State $state, string $updatedAt = self::STALE): Attempt
    {
        return Attempt::fromRow([
            'attempt_id' => self::ATTEMPT_ID,
            'owner_key_hash' => str_repeat('0', 64),
            'quote_id' => 42,
            'store_id' => 1,
            'reserved_increment_id' => self::RESERVED,
            'currency' => 'USD',
            'grand_total_cents' => 123456,
            'payment_method' => 'affirm_gateway',
            'snapshot_hash' => str_repeat('1', 64),
            'state' => $state->value,
            'created_at' => '2026-10-07 09:00:00',
            'updated_at' => $updatedAt,
            'expires_at' => '2026-10-07 10:30:00',
        ]);
    }

    /**
     * An order; by default the one placed for the attempt.
     *
     * @param array<string, mixed> $overrides entity_id, increment_id, quote_id, method, attempt_id and payment
     *        (false: the order has no payment)
     * @return OrderInterface&MockObject
     */
    private function order(array $overrides = []): OrderInterface
    {
        $data = array_replace([
            'entity_id' => 555,
            'increment_id' => self::RESERVED,
            'quote_id' => 42,
            'method' => 'affirm_gateway',
            'attempt_id' => self::ATTEMPT_ID,
            'payment' => true,
        ], $overrides);

        $payment = $this->createMock(OrderPaymentInterface::class);
        $payment->method('getMethod')->willReturn($data['method']);
        $information = ['method_title' => 'Affirm'];
        if ($data['attempt_id'] !== null) {
            $information['hyva_affirm_attempt_id'] = $data['attempt_id'];
        }
        $payment->method('getAdditionalInformation')->willReturn($information);
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn($data['entity_id']);
        $order->method('getIncrementId')->willReturn($data['increment_id']);
        $order->method('getQuoteId')->willReturn($data['quote_id']);
        $order->method('getPayment')->willReturn($data['payment'] ? $payment : null);

        return $order;
    }
}
