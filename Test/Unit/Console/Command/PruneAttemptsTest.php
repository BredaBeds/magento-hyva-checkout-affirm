<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Test\Unit\Console\Command;

use Hyva\CheckoutAffirm\Console\Command\PruneAttempts;
use Hyva\CheckoutAffirm\Model\Attempt\AttemptRepositoryInterface;
use Magento\Framework\Console\Cli;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class PruneAttemptsTest extends TestCase
{
    /**
     * @var AttemptRepositoryInterface&MockObject
     */
    private AttemptRepositoryInterface $attempts;

    /**
     * @var CommandTester
     */
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->attempts = $this->createMock(AttemptRepositoryInterface::class);
        $this->tester = new CommandTester(new PruneAttempts($this->attempts));
    }

    public function testIsConfigured(): void
    {
        $command = new PruneAttempts($this->attempts);

        $this->assertSame('hyva:affirm:attempt:prune', $command->getName());
        $this->assertSame('90', $command->getDefinition()->getOption('days')->getDefault());
        $this->assertStringContainsString('never deleted', $command->getHelp());
    }

    public function testDefaultRetentionIsNinetyDays(): void
    {
        $this->attempts->expects($this->once())->method('prune')->with(90)->willReturn(4);

        $status = $this->tester->execute([]);

        $this->assertSame(Cli::RETURN_SUCCESS, $status);
        $this->assertStringContainsString('Deleted 4 attempt(s) older than 90 day(s).', $this->tester->getDisplay());
    }

    public function testRetentionCanBeChosen(): void
    {
        $this->attempts->expects($this->once())->method('prune')->with(7)->willReturn(0);

        $status = $this->tester->execute(['--days' => '7']);

        $this->assertSame(Cli::RETURN_SUCCESS, $status);
        $this->assertStringContainsString('Deleted 0 attempt(s) older than 7 day(s).', $this->tester->getDisplay());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidDays(): array
    {
        return [
            'zero' => ['0'],
            'negative' => ['-5'],
            'fraction' => ['1.5'],
            'text' => ['ninety'],
            'empty' => [''],
        ];
    }

    #[DataProvider('invalidDays')]
    public function testInvalidRetentionIsRejected(string $days): void
    {
        $this->attempts->expects($this->never())->method('prune');

        $status = $this->tester->execute(['--days' => $days]);

        $this->assertSame(Cli::RETURN_FAILURE, $status);
        $this->assertStringContainsString('--days must be a whole number of at least 1', $this->tester->getDisplay());
    }
}
