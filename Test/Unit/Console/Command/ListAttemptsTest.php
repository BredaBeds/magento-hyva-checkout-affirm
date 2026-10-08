<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Test\Unit\Console\Command;

use Hyva\CheckoutAffirm\Console\Command\ListAttempts;
use Hyva\CheckoutAffirm\Model\Attempt\Attempt;
use Hyva\CheckoutAffirm\Model\Attempt\AttemptRepositoryInterface;
use Hyva\CheckoutAffirm\Model\Attempt\State;
use Hyva\CheckoutAffirm\Model\ResourceModel\Attempt\Lookup;
use Magento\Framework\Console\Cli;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class ListAttemptsTest extends TestCase
{
    /**
     * @var AttemptRepositoryInterface&MockObject
     */
    private AttemptRepositoryInterface $attempts;

    /**
     * @var Lookup&MockObject
     */
    private Lookup $lookup;

    /**
     * @var CommandTester
     */
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->attempts = $this->createMock(AttemptRepositoryInterface::class);
        $this->lookup = $this->createMock(Lookup::class);
        $this->tester = new CommandTester(new ListAttempts($this->attempts, $this->lookup));
    }

    public function testIsConfigured(): void
    {
        $command = new ListAttempts($this->attempts, $this->lookup);

        $this->assertSame('hyva:affirm:attempt:list', $command->getName());
        $definition = $command->getDefinition();
        $this->assertTrue($definition->hasOption('state'));
        $this->assertTrue($definition->getOption('state')->isArray());
        $this->assertTrue($definition->hasOption('all'));
        $this->assertSame('50', $definition->getOption('limit')->getDefault());
    }

    public function testDefaultListsTheUnresolvedAttempts(): void
    {
        $uncertain = $this->attempt(State::UNCERTAIN, [
            'provider_transaction_id' => 'TX-1',
            'failure_reason' => null,
        ]);
        $this->attempts->expects($this->once())->method('listUnresolved')->with(50)->willReturn([$uncertain]);
        $this->lookup->expects($this->never())->method('findByStates');

        $status = $this->tester->execute([]);
        $display = $this->tester->getDisplay();

        $this->assertSame(Cli::RETURN_SUCCESS, $status);
        $this->assertStringContainsString($uncertain->attemptId, $display);
        $this->assertStringContainsString('uncertain', $display);
        $this->assertStringContainsString('000000123', $display);
        $this->assertStringContainsString('1234.56 USD', $display);
        $this->assertStringContainsString('TX-1', $display);
        $this->assertStringContainsString('2026-10-07 09:30:00', $display);
        $this->assertStringContainsString(
            'hyva:affirm:attempt:resolve <attempt-id> --outcome=placed --order=<order-number>',
            $display
        );
    }

    public function testEmptyDefaultListSaysNothingNeedsReconciliation(): void
    {
        $this->attempts->method('listUnresolved')->willReturn([]);

        $status = $this->tester->execute([]);

        $this->assertSame(Cli::RETURN_SUCCESS, $status);
        $this->assertStringContainsString('No unresolved attempts', $this->tester->getDisplay());
    }

    public function testStatesMayBeRepeatedOrCommaSeparated(): void
    {
        $this->attempts->expects($this->never())->method('listUnresolved');
        $this->lookup->expects($this->once())
            ->method('findByStates')
            ->with([State::UNCERTAIN, State::PLACING, State::FAILED], 50)
            ->willReturn([$this->attempt(State::FAILED, ['failure_reason' => 'cart_changed'])]);

        $status = $this->tester->execute(['--state' => ['uncertain, placing', 'UNCERTAIN', ' failed ', '']]);

        $this->assertSame(Cli::RETURN_SUCCESS, $status);
        $this->assertStringContainsString('cart_changed', $this->tester->getDisplay());
        $this->assertStringNotContainsString('--outcome=placed', $this->tester->getDisplay());
    }

    public function testAllListsEveryState(): void
    {
        $this->lookup->expects($this->once())->method('findByStates')->with([], 20)->willReturn([]);

        $status = $this->tester->execute(['--all' => true, '--limit' => '20']);

        $this->assertSame(Cli::RETURN_SUCCESS, $status);
        $this->assertStringContainsString('No attempts found.', $this->tester->getDisplay());
    }

    public function testUnknownStateIsRejected(): void
    {
        $this->lookup->expects($this->never())->method('findByStates');
        $this->attempts->expects($this->never())->method('listUnresolved');

        $status = $this->tester->execute(['--state' => ['uncertain,bogus']]);

        $this->assertSame(Cli::RETURN_FAILURE, $status);
        $this->assertStringContainsString('Unknown state "bogus"', $this->tester->getDisplay());
        $this->assertStringContainsString('initiated, confirmed, placing', $this->tester->getDisplay());
    }

    public function testStateAndAllCannotBeCombined(): void
    {
        $this->lookup->expects($this->never())->method('findByStates');

        $status = $this->tester->execute(['--state' => ['failed'], '--all' => true]);

        $this->assertSame(Cli::RETURN_FAILURE, $status);
        $this->assertStringContainsString('either --state or --all', $this->tester->getDisplay());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidLimits(): array
    {
        return [
            'zero' => ['0'],
            'negative' => ['-1'],
            'text' => ['many'],
            'fraction' => ['1.5'],
            'empty' => [''],
            'too large' => ['1001'],
        ];
    }

    #[DataProvider('invalidLimits')]
    public function testInvalidLimitIsRejected(string $limit): void
    {
        $this->attempts->expects($this->never())->method('listUnresolved');

        $status = $this->tester->execute(['--limit' => $limit]);

        $this->assertSame(Cli::RETURN_FAILURE, $status);
        $this->assertStringContainsString('--limit must be a whole number from 1 to 1000', $this->tester->getDisplay());
    }

    public function testFullPageIsAnnounced(): void
    {
        $this->attempts->method('listUnresolved')->with(1)->willReturn([$this->attempt(State::PLACING)]);

        $this->tester->execute(['--limit' => '1']);

        $this->assertStringContainsString('Showing the first 1 attempts', $this->tester->getDisplay());
    }

    public function testStoredValuesArePrintedLiterally(): void
    {
        $this->lookup->method('findByStates')->willReturn([
            $this->attempt(State::FAILED, [
                'failure_reason' => '<error>boom</error>',
                'reserved_increment_id' => "0001\e[31m",
            ]),
        ]);

        $this->tester->execute(['--all' => true]);
        $display = $this->tester->getDisplay();

        $this->assertStringContainsString('<error>boom</error>', $display);
        $this->assertStringContainsString('0001[31m', $display);
        $this->assertStringNotContainsString("\e", $display);
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
