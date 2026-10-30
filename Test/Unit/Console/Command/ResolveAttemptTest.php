<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Test\Unit\Console\Command;

use Hyva\CheckoutAffirm\Console\Command\ResolveAttempt;
use Hyva\CheckoutAffirm\Model\Attempt\AdminNotifier;
use Hyva\CheckoutAffirm\Model\Attempt\Attempt;
use Hyva\CheckoutAffirm\Model\Attempt\AttemptRepositoryInterface;
use Hyva\CheckoutAffirm\Model\Attempt\PlacementContext;
use Hyva\CheckoutAffirm\Model\Attempt\StaleAttemptPolicy;
use Hyva\CheckoutAffirm\Model\Attempt\State;
use Hyva\CheckoutAffirm\Model\Checkout\AttemptRecovery;
use Hyva\CheckoutAffirm\Model\Checkout\OrderPlacement;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Console\Cli;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Model\ResourceModel\Quote as QuoteResource;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;

class ResolveAttemptTest extends TestCase
{
    private const NOW = 1791367200; // 2026-10-07 10:00:00 UTC

    /**
     * @var AttemptRepositoryInterface&MockObject
     */
    private AttemptRepositoryInterface $attempts;

    /**
     * @var OrderRepositoryInterface&MockObject
     */
    private OrderRepositoryInterface $orderRepository;

    /**
     * @var CommandTester
     */
    private CommandTester $tester;

    /**
     * @var array<string, array<int, OrderInterface>> orders by "increment_id|store_id"
     */
    private array $orders = [];

    /**
     * @var list<array{0: string, 1: mixed}>
     */
    private array $filters = [];

    /**
     * @var Attempt|null
     */
    private ?Attempt $stored = null;

    /**
     * @var list<array{0: string, 1: array<State|string>, 2: State|string, 3: array<string, mixed>}>
     */
    private array $transitions = [];

    /**
     * @var bool
     */
    private bool $transitionResult = true;

    /**
     * @var list<array{0: array<string, mixed>, 1: array<string, mixed>}>
     */
    private array $quoteUpdates = [];

    /**
     * @var SearchCriteriaBuilder&MockObject
     */
    private SearchCriteriaBuilder $criteriaBuilder;

    /**
     * @var DateTime&MockObject
     */
    private DateTime $dateTime;

    protected function setUp(): void
    {
        $this->attempts = $this->createMock(AttemptRepositoryInterface::class);
        $this->attempts->method('get')->willReturnCallback(
            fn (string $id): ?Attempt => $this->stored !== null && $this->stored->attemptId === $id
                ? $this->stored
                : null
        );
        $this->attempts->method('transition')->willReturnCallback(
            function (string $id, array $from, State|string $to, array $set = []): bool {
                $this->transitions[] = [$id, $from, $to, $set];

                return $this->transitionResult;
            }
        );

        $criteria = $this->createMock(SearchCriteria::class);
        $builder = $this->createMock(SearchCriteriaBuilder::class);
        $builder->method('addFilter')->willReturnCallback(function (string $field, mixed $value) use ($builder) {
            $this->filters[] = [$field, $value];

            return $builder;
        });
        $builder->method('setPageSize')->willReturnSelf();
        $builder->method('create')->willReturn($criteria);

        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->orderRepository->method('getList')->willReturnCallback(function () {
            $filters = array_column($this->filters, 1, 0);
            $this->filters = [];
            $result = $this->createMock(OrderSearchResultInterface::class);
            $result->method('getItems')->willReturn(
                $this->orders[$filters['increment_id'] . '|' . $filters['store_id']] ?? []
            );

            return $result;
        });

        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn(self::NOW);
        $this->criteriaBuilder = $builder;
        $this->dateTime = $dateTime;

        $this->tester = new CommandTester($this->command(new StaleAttemptPolicy()));
    }

    /**
     * The command with the real order lookup and attempt rules over the mocked repositories.
     *
     * @param StaleAttemptPolicy $policy
     * @return ResolveAttempt
     */
    private function command(StaleAttemptPolicy $policy): ResolveAttempt
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('update')->willReturnCallback(function (string $table, array $bind, array $where): int {
            $this->quoteUpdates[] = [$bind, $where];

            return 1;
        });
        $quoteResource = $this->createMock(QuoteResource::class);
        $quoteResource->method('getConnection')->willReturn($connection);
        $quoteResource->method('getMainTable')->willReturn('quote');
        $orderPlacement = new OrderPlacement(
            $this->createMock(CartManagementInterface::class),
            new PlacementContext(),
            $this->orderRepository,
            $this->criteriaBuilder,
            $quoteResource
        );
        $recovery = new AttemptRecovery(
            $this->attempts,
            $orderPlacement,
            $this->createMock(AdminNotifier::class),
            $policy,
            $this->createMock(LoggerInterface::class)
        );

        return new ResolveAttempt($this->attempts, $orderPlacement, $recovery, $this->dateTime, $policy);
    }

    public function testIsConfigured(): void
    {
        $command = $this->command(new StaleAttemptPolicy());

        $this->assertSame('hyva:affirm:attempt:resolve', $command->getName());
        $definition = $command->getDefinition();
        $this->assertTrue($definition->getArgument('attempt')->isRequired());
        $this->assertTrue($definition->hasOption('outcome'));
        $this->assertTrue($definition->hasOption('order'));
        $this->assertTrue($definition->hasOption('force'));
        $this->assertStringContainsString('Reconciliation procedure', $command->getHelp());
        $this->assertStringContainsString('15 minutes', $command->getHelp());
        $this->assertStringContainsString('hyva_affirm_attempt_escalate', $command->getHelp());
    }

    public function testForceUsesTheSharedStaleThreshold(): void
    {
        $tester = new CommandTester($this->command(new StaleAttemptPolicy(1800)));
        // Unchanged for 20 minutes: enough for the default, not for a 30-minute threshold.
        $this->stored = $this->attempt(State::PLACING, ['updated_at' => '2026-10-07 09:40:00']);

        $status = $tester->execute([
            'attempt' => $this->stored->attemptId,
            '--outcome' => 'voided',
            '--force' => true,
        ]);

        $this->assertSame(Cli::RETURN_FAILURE, $status);
        $this->assertStringContainsString('has not changed for 30 minutes', $tester->getDisplay());
        $this->assertSame([], $this->transitions);
    }

    public function testMalformedAttemptIdIsRejected(): void
    {
        $this->attempts->expects($this->never())->method('get');

        $status = $this->tester->execute(['attempt' => 'abc', '--outcome' => 'voided']);

        $this->assertSame(Cli::RETURN_FAILURE, $status);
        $this->assertStringContainsString('64 hexadecimal characters', $this->tester->getDisplay());
    }

    public function testUnknownOutcomeIsRejectedWithTheProcedure(): void
    {
        $this->attempts->expects($this->never())->method('get');

        $status = $this->resolve(['--outcome' => 'refunded']);

        $this->assertSame(Cli::RETURN_FAILURE, $status);
        $this->assertStringContainsString('--outcome must be "placed" or "voided"', $this->tester->getDisplay());
        $this->assertStringContainsString('Reconciliation procedure', $this->tester->getDisplay());
        $this->assertStringContainsString(
            'hyva:affirm:attempt:resolve <attempt-id> --outcome=voided',
            $this->tester->getDisplay()
        );
    }

    public function testMissingOutcomeIsRejected(): void
    {
        $status = $this->resolve([]);

        $this->assertSame(Cli::RETURN_FAILURE, $status);
        $this->assertStringContainsString('--outcome must be', $this->tester->getDisplay());
    }

    public function testPlacedNeedsAnOrderNumber(): void
    {
        $this->attempts->expects($this->never())->method('get');

        $status = $this->resolve(['--outcome' => 'placed', '--order' => '  ']);

        $this->assertSame(Cli::RETURN_FAILURE, $status);
        $this->assertStringContainsString('needs the order number', $this->tester->getDisplay());
    }

    public function testVoidedTakesNoOrderNumber(): void
    {
        $status = $this->resolve(['--outcome' => 'voided', '--order' => '000000123']);

        $this->assertSame(Cli::RETURN_FAILURE, $status);
        $this->assertStringContainsString('--order is only used with --outcome=placed', $this->tester->getDisplay());
    }

    public function testOverlongOrderNumberIsRejected(): void
    {
        $status = $this->resolve(['--outcome' => 'placed', '--order' => str_repeat('1', 51)]);

        $this->assertSame(Cli::RETURN_FAILURE, $status);
        $this->assertStringContainsString('not a valid order number', $this->tester->getDisplay());
    }

    public function testUnknownAttemptIsReported(): void
    {
        $status = $this->resolve(['--outcome' => 'voided']);

        $this->assertSame(Cli::RETURN_FAILURE, $status);
        $this->assertStringContainsString('was not found', $this->tester->getDisplay());
        $this->assertSame([], $this->transitions);
    }

    /**
     * @return array<string, array{0: State, 1: string}>
     */
    public static function finishedOrOpenStates(): array
    {
        return [
            'initiated' => [State::INITIATED, 'Only uncertain attempts can be resolved'],
            'confirmed' => [State::CONFIRMED, 'Only uncertain attempts can be resolved'],
            'failed' => [State::FAILED, 'Only uncertain attempts can be resolved'],
            'placed' => [State::PLACED, 'was placed (order #000000123)'],
            'resolved' => [State::RESOLVED, 'already resolved (resolved_voided)'],
        ];
    }

    #[DataProvider('finishedOrOpenStates')]
    public function testOnlyUnresolvedAttemptsCanBeResolved(State $state, string $message): void
    {
        $this->stored = $this->attempt($state, [
            'order_increment_id' => $state === State::PLACED ? '000000123' : null,
            'failure_reason' => $state === State::RESOLVED ? Attempt::REASON_RESOLVED_VOIDED : null,
        ]);

        $status = $this->resolve(['--outcome' => 'voided', '--force' => true]);

        $this->assertSame(Cli::RETURN_FAILURE, $status);
        $this->assertStringContainsString($message, $this->tester->getDisplay());
        $this->assertSame([], $this->transitions);
    }

    /**
     * @return array<string, array{0: State}>
     */
    public static function stuckStates(): array
    {
        return ['placing' => [State::PLACING], 'authorizing' => [State::AUTHORIZING]];
    }

    #[DataProvider('stuckStates')]
    public function testPlacementInProgressNeedsForce(State $state): void
    {
        $this->stored = $this->attempt($state, ['updated_at' => '2026-10-07 08:00:00']);

        $status = $this->resolve(['--outcome' => 'voided']);

        $this->assertSame(Cli::RETURN_FAILURE, $status);
        $this->assertStringContainsString('may still be running', $this->tester->getDisplay());
        $this->assertStringContainsString('--force', $this->tester->getDisplay());
        $this->assertSame([], $this->transitions);
    }

    #[DataProvider('stuckStates')]
    public function testForceIsRefusedForARecentChange(State $state): void
    {
        $this->stored = $this->attempt($state, ['updated_at' => '2026-10-07 09:50:00']);

        $status = $this->resolve(['--outcome' => 'voided', '--force' => true]);

        $this->assertSame(Cli::RETURN_FAILURE, $status);
        $this->assertStringContainsString('changed 10 minute(s) ago', $this->tester->getDisplay());
        $this->assertSame([], $this->transitions);
    }

    public function testForceIsRefusedWhenTheLastChangeCannotBeRead(): void
    {
        $this->stored = $this->attempt(State::PLACING, ['updated_at' => 'yesterday']);

        $status = $this->resolve(['--outcome' => 'voided', '--force' => true]);

        $this->assertSame(Cli::RETURN_FAILURE, $status);
        $this->assertStringContainsString('cannot be read', $this->tester->getDisplay());
    }

    #[DataProvider('stuckStates')]
    public function testForceResolvesAStuckAttemptFromTheStateItWasRead(State $state): void
    {
        $this->stored = $this->attempt($state, ['updated_at' => '2026-10-07 09:45:00']);

        $status = $this->resolve(['--outcome' => 'voided', '--force' => true]);

        $this->assertSame(Cli::RETURN_SUCCESS, $status, $this->tester->getDisplay());
        $this->assertSame(
            [[$this->stored->attemptId, [$state], State::RESOLVED, ['failure_reason' => 'resolved_voided']]],
            $this->transitions
        );
    }

    public function testVoidedReleasesTheCart(): void
    {
        $this->stored = $this->attempt(State::UNCERTAIN, ['provider_transaction_id' => 'TX-9']);
        // An order with the reserved number from another cart does not stand in the way.
        $this->orders['000000123|1'] = [$this->order('000000123', 99, 'affirm_gateway')];

        $status = $this->resolve(['--outcome' => 'VOIDED']);
        $display = $this->tester->getDisplay();

        $this->assertSame(Cli::RETURN_SUCCESS, $status, $display);
        $this->assertSame(
            [[$this->stored->attemptId, [State::UNCERTAIN], State::RESOLVED, ['failure_reason' => 'resolved_voided']]],
            $this->transitions
        );
        $this->assertStringContainsString('resolved as voided', $display);
        $this->assertStringContainsString('quote 42', $display);
        $this->assertStringContainsString('order reference #000000123 (Affirm transaction TX-9)', $display);
    }

    public function testVoidedIsRefusedWhenAnAffirmOrderExistsForTheAttempt(): void
    {
        $this->stored = $this->attempt(State::UNCERTAIN);
        $this->orders['000000123|1'] = [$this->order('000000123', 42, 'affirm_gateway')];

        $status = $this->resolve(['--outcome' => 'voided']);

        $this->assertSame(Cli::RETURN_FAILURE, $status);
        $this->assertStringContainsString('--outcome=placed --order=000000123', $this->tester->getDisplay());
        $this->assertSame([], $this->transitions);
    }

    public function testVoidedIsAllowedWhenTheOrderWasPaidOtherwise(): void
    {
        $this->stored = $this->attempt(State::UNCERTAIN);
        $this->orders['000000123|1'] = [$this->order('000000123', 42, 'checkmo')];

        $status = $this->resolve(['--outcome' => 'voided']);

        $this->assertSame(Cli::RETURN_SUCCESS, $status, $this->tester->getDisplay());
    }

    public function testPlacedLinksTheOrder(): void
    {
        $this->stored = $this->attempt(State::UNCERTAIN);
        $this->orders['000000123|1'] = [$this->order('000000123', 42, 'affirm_gateway', 555)];

        $status = $this->resolve(['--outcome' => 'placed', '--order' => ' 000000123 ']);
        $display = $this->tester->getDisplay();

        $this->assertSame(Cli::RETURN_SUCCESS, $status, $display);
        $this->assertSame([[
            $this->stored->attemptId,
            [State::UNCERTAIN],
            State::RESOLVED,
            ['failure_reason' => 'resolved_placed', 'order_increment_id' => '000000123', 'order_id' => 555],
        ]], $this->transitions);
        $this->assertStringContainsString('resolved as placed with order #000000123', $display);
        $this->assertStringNotContainsString('reserved order number', $display);
        $this->assertSame([[['is_active' => 0], ['entity_id = ?' => 42, 'is_active = ?' => 1]]], $this->quoteUpdates);
    }

    public function testPlacedRefusesAnOrderOfAnotherAttemptOfTheCart(): void
    {
        $this->stored = $this->attempt(State::UNCERTAIN);
        $this->orders['000000123|1'] = [$this->order('000000123', 42, 'affirm_gateway', 555, str_repeat('cd', 32))];

        $status = $this->resolve(['--outcome' => 'placed', '--order' => '000000123']);

        $this->assertSame(Cli::RETURN_FAILURE, $status);
        $this->assertStringContainsString('by another checkout attempt', $this->tester->getDisplay());
        $this->assertSame([], $this->transitions);
        $this->assertSame([], $this->quoteUpdates);
    }

    public function testPlacedWithAnotherOrderNumberOfTheSameCartIsNoted(): void
    {
        $this->stored = $this->attempt(State::UNCERTAIN, ['store_id' => 2]);
        $this->orders['000000200|2'] = [$this->order('000000200', 42, 'affirm_gateway', 600)];

        $status = $this->resolve(['--outcome' => 'placed', '--order' => '000000200']);

        $this->assertSame(Cli::RETURN_SUCCESS, $status, $this->tester->getDisplay());
        $this->assertStringContainsString('reserved order number #000000123', $this->tester->getDisplay());
    }

    public function testPlacedNeedsAnOrderInTheStoreOfTheAttempt(): void
    {
        $this->stored = $this->attempt(State::UNCERTAIN, ['store_id' => 2]);
        $this->orders['000000123|1'] = [$this->order('000000123', 42, 'affirm_gateway')];

        $status = $this->resolve(['--outcome' => 'placed', '--order' => '000000123']);

        $this->assertSame(Cli::RETURN_FAILURE, $status);
        $this->assertStringContainsString('No order #000000123 exists in store 2', $this->tester->getDisplay());
        $this->assertSame([], $this->transitions);
    }

    public function testPlacedRefusesAnOrderOfAnotherCart(): void
    {
        $this->stored = $this->attempt(State::UNCERTAIN);
        $this->orders['000000123|1'] = [$this->order('000000123', 7, 'affirm_gateway')];

        $status = $this->resolve(['--outcome' => 'placed', '--order' => '000000123']);

        $this->assertSame(Cli::RETURN_FAILURE, $status);
        $this->assertStringContainsString('not placed from the cart of this attempt', $this->tester->getDisplay());
        $this->assertSame([], $this->transitions);
    }

    public function testPlacedRefusesAnOrderPaidOtherwise(): void
    {
        $this->stored = $this->attempt(State::UNCERTAIN);
        $this->orders['000000123|1'] = [$this->order('000000123', 42, 'checkmo')];

        $status = $this->resolve(['--outcome' => 'placed', '--order' => '000000123']);

        $this->assertSame(Cli::RETURN_FAILURE, $status);
        $this->assertStringContainsString('paid with "checkmo"', $this->tester->getDisplay());
        $this->assertStringContainsString('resolve the attempt as voided', $this->tester->getDisplay());
    }

    public function testLostRaceIsReported(): void
    {
        $this->stored = $this->attempt(State::UNCERTAIN);
        $this->transitionResult = false;

        $status = $this->resolve(['--outcome' => 'voided']);

        $this->assertSame(Cli::RETURN_FAILURE, $status);
        $this->assertStringContainsString('changed while it was being resolved', $this->tester->getDisplay());
    }

    public function testUppercaseAttemptIdIsAccepted(): void
    {
        $this->stored = $this->attempt(State::UNCERTAIN);

        $status = $this->tester->execute([
            'attempt' => ' ' . strtoupper($this->stored->attemptId) . ' ',
            '--outcome' => 'voided',
        ]);

        $this->assertSame(Cli::RETURN_SUCCESS, $status, $this->tester->getDisplay());
    }

    public function testOrderNumbersArePrintedLiterally(): void
    {
        $this->stored = $this->attempt(State::UNCERTAIN);

        $this->resolve(['--outcome' => 'placed', '--order' => '<error>1</error>']);

        $this->assertStringContainsString('No order #<error>1</error> exists', $this->tester->getDisplay());
    }

    /**
     * @param array<string, mixed> $options
     * @return int
     */
    private function resolve(array $options): int
    {
        return $this->tester->execute(['attempt' => $this->stored?->attemptId ?? str_repeat('cd', 32)] + $options);
    }

    /**
     * @param string $incrementId
     * @param int $quoteId
     * @param string $method
     * @param int $entityId
     * @return OrderInterface&MockObject
     */
    private function order(
        string $incrementId,
        int $quoteId,
        string $method,
        int $entityId = 500,
        ?string $attemptId = null
    ): OrderInterface {
        $payment = $this->createMock(OrderPaymentInterface::class);
        $payment->method('getMethod')->willReturn($method);
        $payment->method('getAdditionalInformation')
            ->willReturn(['hyva_affirm_attempt_id' => $attemptId ?? str_repeat('ab', 32)]);
        $order = $this->createMock(OrderInterface::class);
        $order->method('getIncrementId')->willReturn($incrementId);
        $order->method('getQuoteId')->willReturn((string) $quoteId);
        $order->method('getEntityId')->willReturn((string) $entityId);
        $order->method('getPayment')->willReturn($payment);

        return $order;
    }

    /**
     * @param State $state
     * @param array<string, mixed> $overrides
     * @return Attempt
     */
    private function attempt(State $state, array $overrides = []): Attempt
    {
        return Attempt::fromRow(array_replace([
            'attempt_id' => str_repeat('ab', 32),
            'owner_key_hash' => str_repeat('0', 64),
            'quote_id' => 42,
            'store_id' => 1,
            'customer_id' => null,
            'reserved_increment_id' => '000000123',
            'currency' => 'USD',
            'grand_total_cents' => 123456,
            'payment_method' => 'affirm_gateway',
            'snapshot_hash' => str_repeat('1', 64),
            'state' => $state->value,
            'token_hash' => null,
            'order_increment_id' => null,
            'order_id' => null,
            'failure_reason' => null,
            'provider_transaction_id' => null,
            'created_at' => '2026-10-07 09:00:00',
            'updated_at' => '2026-10-07 09:30:00',
            'expires_at' => '2026-10-07 10:00:00',
        ], $overrides));
    }
}
