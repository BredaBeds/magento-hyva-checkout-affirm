<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Test\Unit\Cron;

use Hyva\CheckoutAffirm\Cron\CleanupAttempts;
use Hyva\CheckoutAffirm\Model\Attempt\Attempt;
use Hyva\CheckoutAffirm\Model\Attempt\AttemptRepositoryInterface;
use Hyva\CheckoutAffirm\Model\Attempt\State;
use Hyva\CheckoutAffirm\Model\ResourceModel\Attempt\Lookup;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class CleanupAttemptsTest extends TestCase
{
    private const NOW = 1791367200; // 2026-10-07 10:00:00 UTC

    /**
     * @var AttemptRepositoryInterface&MockObject
     */
    private AttemptRepositoryInterface $attempts;

    /**
     * @var Lookup&MockObject
     */
    private Lookup $lookup;

    /**
     * @var DateTime&MockObject
     */
    private DateTime $dateTime;

    /**
     * @var list<array{0: string, 1: string, 2: array<string, mixed>}>
     */
    private array $logs = [];

    /**
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface $logger;

    /**
     * @var list<mixed>
     */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->attempts = $this->createMock(AttemptRepositoryInterface::class);
        $this->lookup = $this->createMock(Lookup::class);
        $this->dateTime = $this->createMock(DateTime::class);
        $this->dateTime->method('gmtTimestamp')->willReturn(self::NOW);
        $this->logger = $this->createMock(LoggerInterface::class);
        foreach (['info', 'error', 'warning'] as $level) {
            $this->logger->method($level)->willReturnCallback(
                function (string $message, array $context = []) use ($level): void {
                    $this->logs[] = [$level, $message, $context];
                }
            );
        }
    }

    public function testDeletesFirstThenExpiresOpenAttemptsFromTheirReadState(): void
    {
        $initiated = $this->attempt(State::INITIATED, 'a');
        $confirmed = $this->attempt(State::CONFIRMED, 'b');
        $this->attempts->expects($this->once())->method('prune')->with(90)->willReturnCallback(function (): int {
            $this->calls[] = 'prune';

            return 3;
        });
        $this->lookup->expects($this->once())
            ->method('findExpiredOpen')
            ->with(self::NOW - 900, 500)
            ->willReturnCallback(function () use ($initiated, $confirmed): array {
                $this->calls[] = 'find';

                return [$initiated, $confirmed];
            });
        $this->attempts->expects($this->exactly(2))->method('transition')->willReturnCallback(
            function (string $id, array $from, State|string $to, array $set = [], array $conditions = []): bool {
                $this->calls[] = [$id, $from, $to, $set, $conditions];

                return true;
            }
        );

        $this->cron()->execute();

        $this->assertSame([
            'prune',
            'find',
            [$initiated->attemptId, [State::INITIATED], State::FAILED, ['failure_reason' => 'expired'], []],
            [$confirmed->attemptId, [State::CONFIRMED], State::FAILED, ['failure_reason' => 'expired'], []],
        ], $this->calls);
        $this->assertSame(
            [['info', 'Affirm checkout attempt cleanup finished.', ['deleted' => 3, 'expired' => 2]]],
            $this->logs
        );
    }

    public function testRetentionAndGraceComeFromConfiguration(): void
    {
        $this->attempts->expects($this->once())->method('prune')->with(30)->willReturn(0);
        $this->lookup->expects($this->once())->method('findExpiredOpen')->with(self::NOW - 60, 500)->willReturn([]);

        $this->cron(retentionDays: 30, expiryGraceSeconds: 60)->execute();

        $this->assertSame([], $this->logs, 'Nothing to report when nothing changed.');
    }

    public function testLostCompareAndSwapIsNotCounted(): void
    {
        $this->attempts->method('prune')->willReturn(0);
        $this->lookup->method('findExpiredOpen')->willReturn([
            $this->attempt(State::INITIATED, 'a'),
            $this->attempt(State::INITIATED, 'b'),
        ]);
        $this->attempts->method('transition')->willReturnOnConsecutiveCalls(false, true);

        $this->cron()->execute();

        $this->assertSame(['deleted' => 0, 'expired' => 1], $this->logs[0][2]);
    }

    public function testWorksThroughFullBatchesUpToTheLimit(): void
    {
        $this->attempts->method('prune')->willReturn(0);
        $this->attempts->method('transition')->willReturn(true);
        $this->lookup->expects($this->exactly(2))
            ->method('findExpiredOpen')
            ->with(self::NOW - 900, 2)
            ->willReturnOnConsecutiveCalls(
                [$this->attempt(State::INITIATED, 'a'), $this->attempt(State::INITIATED, 'b')],
                [$this->attempt(State::CONFIRMED, 'c')]
            );

        $this->cron(batchSize: 2)->execute();

        $this->assertSame(3, $this->logs[0][2]['expired']);
    }

    public function testStopsAfterTheMaximumNumberOfBatches(): void
    {
        $this->attempts->method('prune')->willReturn(0);
        $this->attempts->method('transition')->willReturn(true);
        $this->lookup->expects($this->exactly(3))
            ->method('findExpiredOpen')
            ->willReturnCallback(fn (): array => [$this->attempt(State::INITIATED, 'a')]);

        $this->cron(batchSize: 1, maxBatches: 3)->execute();

        $this->assertSame(3, $this->logs[0][2]['expired']);
    }

    public function testAFailingDeletionStillExpiresAndFailsTheJob(): void
    {
        $id = str_repeat('e', 64);
        $this->attempts->method('prune')->willThrowException(
            new RuntimeException("Deadlock, query was: DELETE ... WHERE attempt_id = '$id'")
        );
        $this->lookup->method('findExpiredOpen')->willReturn([$this->attempt(State::INITIATED, 'a')]);
        $this->attempts->expects($this->once())->method('transition')->willReturn(true);

        try {
            $this->cron()->execute();
            $this->fail('The job must report the failure.');
        } catch (LocalizedException $exception) {
            $this->assertStringContainsString('See the log', $exception->getMessage());
        }

        $this->assertSame('error', $this->logs[0][0]);
        $this->assertSame('prune', $this->logs[0][2]['step']);
        $this->assertSame(RuntimeException::class, $this->logs[0][2]['exception_class']);
        $this->assertStringContainsString('[redacted]', $this->logs[0][2]['message']);
        $this->assertStringNotContainsString($id, (string) json_encode($this->logs));
        $this->assertSame(
            ['info', 'Affirm checkout attempt cleanup finished.', ['deleted' => 0, 'expired' => 1]],
            $this->logs[1]
        );
    }

    public function testAFailingExpiryIsLoggedWithoutAttemptIds(): void
    {
        $attempt = $this->attempt(State::CONFIRMED, 'f');
        $this->attempts->method('prune')->willReturn(2);
        $this->lookup->method('findExpiredOpen')->willReturn([$attempt]);
        $this->attempts->method('transition')->willThrowException(
            new RuntimeException('Lock wait timeout for ' . strtoupper($attempt->attemptId))
        );

        $this->expectException(LocalizedException::class);
        try {
            $this->cron()->execute();
        } finally {
            $this->assertSame('expire', $this->logs[0][2]['step']);
            $this->assertStringNotContainsString(
                strtolower($attempt->attemptId),
                strtolower((string) json_encode($this->logs))
            );
            $this->assertSame(['deleted' => 2, 'expired' => 0], $this->logs[1][2]);
        }
    }

    public function testAnUnwritableLogAfterTheWorkDoesNotFailTheJob(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects($this->once())
            ->method('info')
            ->willThrowException(new \UnexpectedValueException('The stream "system.log" could not be opened'));
        $this->attempts->expects($this->once())->method('prune')->willReturn(1);
        $this->lookup->method('findExpiredOpen')->willReturn([$this->attempt(State::INITIATED, 'a')]);
        $this->attempts->expects($this->once())->method('transition')->willReturn(true);

        $this->cron()->execute();
    }

    public function testAnUnwritableLogDoesNotHideAFailedStep(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects($this->once())->method('error');
        $this->logger->method('info')->willThrowException(new \UnexpectedValueException('log not writable'));
        $this->attempts->method('prune')->willThrowException(new RuntimeException('Deadlock'));
        $this->lookup->method('findExpiredOpen')->willReturn([$this->attempt(State::INITIATED, 'a')]);
        $this->attempts->expects($this->once())->method('transition')->willReturn(true);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The Affirm checkout attempt cleanup failed.');

        $this->cron()->execute();
    }

    /**
     * @param int $retentionDays
     * @param int $expiryGraceSeconds
     * @param int $batchSize
     * @param int $maxBatches
     * @return CleanupAttempts
     */
    private function cron(
        int $retentionDays = 90,
        int $expiryGraceSeconds = 900,
        int $batchSize = 500,
        int $maxBatches = 20
    ): CleanupAttempts {
        return new CleanupAttempts(
            $this->attempts,
            $this->lookup,
            $this->dateTime,
            $this->logger,
            $retentionDays,
            $expiryGraceSeconds,
            $batchSize,
            $maxBatches
        );
    }

    /**
     * @param State $state
     * @param string $idCharacter
     * @return Attempt
     */
    private function attempt(State $state, string $idCharacter): Attempt
    {
        return Attempt::fromRow([
            'attempt_id' => str_repeat($idCharacter, 64),
            'owner_key_hash' => str_repeat('0', 64),
            'quote_id' => 42,
            'store_id' => 1,
            'reserved_increment_id' => '000000123',
            'currency' => 'USD',
            'grand_total_cents' => 123456,
            'snapshot_hash' => str_repeat('1', 64),
            'state' => $state->value,
            'created_at' => '2026-10-06 08:00:00',
            'updated_at' => '2026-10-06 08:00:00',
            'expires_at' => '2026-10-06 09:00:00',
        ]);
    }
}
