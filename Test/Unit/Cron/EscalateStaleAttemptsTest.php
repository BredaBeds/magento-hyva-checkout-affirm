<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Test\Unit\Cron;

use Hyva\CheckoutAffirm\Cron\EscalateStaleAttempts;
use Hyva\CheckoutAffirm\Model\Attempt\Attempt;
use Hyva\CheckoutAffirm\Model\Attempt\StaleAttemptPolicy;
use Hyva\CheckoutAffirm\Model\Attempt\State;
use Hyva\CheckoutAffirm\Model\Checkout\AttemptRecovery;
use Hyva\CheckoutAffirm\Model\ResourceModel\Attempt\Lookup;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class EscalateStaleAttemptsTest extends TestCase
{
    private const NOW = 1791367200; // 2026-10-07 10:00:00 UTC

    /**
     * @var Lookup&MockObject
     */
    private Lookup $lookup;

    /**
     * @var AttemptRecovery&MockObject
     */
    private AttemptRecovery $recovery;

    /**
     * @var DateTime&MockObject
     */
    private DateTime $dateTime;

    /**
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface $logger;

    /**
     * @var list<array{0: string, 1: string, 2: array<string, mixed>}>
     */
    private array $logs = [];

    protected function setUp(): void
    {
        $this->lookup = $this->createMock(Lookup::class);
        $this->recovery = $this->createMock(AttemptRecovery::class);
        $this->dateTime = $this->createMock(DateTime::class);
        $this->dateTime->method('gmtTimestamp')->willReturn(self::NOW);
        $this->logger = $this->createMock(LoggerInterface::class);
        foreach (['info', 'error'] as $level) {
            $this->logger->method($level)->willReturnCallback(
                function (string $message, array $context = []) use ($level): void {
                    $this->logs[] = [$level, $message, $context];
                }
            );
        }
    }

    public function testEscalatesOneBatchOfStalePlacementsAtTheSharedThreshold(): void
    {
        $placing = $this->attempt(State::PLACING, 'a', '000000101');
        $authorizing = $this->attempt(State::AUTHORIZING, 'b', '000000102');
        $raced = $this->attempt(State::AUTHORIZING, 'c', '000000103');
        $this->lookup->expects($this->once())
            ->method('findStale')
            ->with(self::NOW - 900, 50)
            ->willReturn([$placing, $authorizing, $raced]);
        $escalated = [];
        $this->recovery->expects($this->exactly(3))->method('escalate')->willReturnCallback(
            function (Attempt $attempt, int $now) use (&$escalated): string {
                $this->assertSame(self::NOW, $now);
                $escalated[] = $attempt->reservedIncrementId;

                return match ($attempt->reservedIncrementId) {
                    '000000101' => AttemptRecovery::ESCALATED_PLACED,
                    '000000102' => AttemptRecovery::ESCALATED_UNCERTAIN,
                    default => AttemptRecovery::ESCALATION_SKIPPED,
                };
            }
        );

        $this->cron()->execute();

        $this->assertSame(['000000101', '000000102', '000000103'], $escalated);
        $this->assertSame(
            [['info', 'Affirm checkout: stale attempts escalated.', ['placed' => 1, 'uncertain' => 1]]],
            $this->logs
        );
    }

    public function testThresholdAndBatchSizeAreConfigurable(): void
    {
        $this->lookup->expects($this->once())->method('findStale')->with(self::NOW - 1800, 10)->willReturn([]);
        $this->recovery->expects($this->never())->method('escalate');

        $this->cron(new StaleAttemptPolicy(1800), 10)->execute();

        $this->assertSame([], $this->logs, 'Nothing to report when nothing changed.');
    }

    public function testZeroBatchSizeStillReadsOneAttempt(): void
    {
        $this->lookup->expects($this->once())->method('findStale')->with(self::NOW - 900, 1)->willReturn([]);

        $this->cron(null, 0)->execute();
    }

    public function testAFailingAttemptDoesNotStopTheOthersAndIsReported(): void
    {
        $first = $this->attempt(State::AUTHORIZING, 'a', '000000101');
        $second = $this->attempt(State::PLACING, 'b', '000000102');
        $this->lookup->method('findStale')->willReturn([$first, $second]);
        $this->recovery->method('escalate')->willReturnCallback(
            function (Attempt $attempt): string {
                if ($attempt->reservedIncrementId === '000000101') {
                    throw new RuntimeException('Deadlock on attempt_id ' . $attempt->attemptId);
                }

                return AttemptRecovery::ESCALATED_UNCERTAIN;
            }
        );

        try {
            $this->cron()->execute();
            $this->fail('The run must report the failure to the cron schedule.');
        } catch (LocalizedException $exception) {
            $this->assertSame(
                'Stale Affirm checkout attempts could not all be escalated. See the log for details.',
                $exception->getMessage()
            );
        }

        $this->assertSame([
            [
                'error',
                'Affirm checkout: a stale attempt could not be escalated.',
                ['exception_class' => RuntimeException::class, 'order_reference' => '000000101'],
            ],
            ['info', 'Affirm checkout: stale attempts escalated.', ['placed' => 0, 'uncertain' => 1]],
        ], $this->logs);
        $this->assertStringNotContainsString($first->attemptId, (string) json_encode($this->logs));
    }

    public function testAFailingLookupIsReportedWithoutDetails(): void
    {
        $this->lookup->method('findStale')->willThrowException(
            new RuntimeException('SQLSTATE[42S02] ' . str_repeat('a', 64))
        );
        $this->recovery->expects($this->never())->method('escalate');

        $this->expectException(LocalizedException::class);
        try {
            $this->cron()->execute();
        } finally {
            $this->assertSame([[
                'error',
                'Affirm checkout: a stale attempt could not be escalated.',
                ['exception_class' => RuntimeException::class],
            ]], $this->logs);
        }
    }

    public function testAnUnwritableSummaryLogDoesNotFailTheRun(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('info')->willThrowException(new RuntimeException('system.log is not writable'));
        $this->lookup->method('findStale')->willReturn([$this->attempt(State::PLACING, 'a', '000000101')]);
        $this->recovery->method('escalate')->willReturn(AttemptRecovery::ESCALATED_UNCERTAIN);

        (new EscalateStaleAttempts(
            $this->lookup,
            $this->recovery,
            new StaleAttemptPolicy(),
            $this->dateTime,
            $logger
        ))->execute();

        $this->addToAssertionCount(1);
    }

    private function cron(?StaleAttemptPolicy $policy = null, int $batchSize = 50): EscalateStaleAttempts
    {
        return new EscalateStaleAttempts(
            $this->lookup,
            $this->recovery,
            $policy ?? new StaleAttemptPolicy(),
            $this->dateTime,
            $this->logger,
            $batchSize
        );
    }

    private function attempt(State $state, string $idCharacter, string $reserved): Attempt
    {
        return Attempt::fromRow([
            'attempt_id' => str_repeat($idCharacter, 64),
            'owner_key_hash' => str_repeat('0', 64),
            'quote_id' => 42,
            'store_id' => 1,
            'reserved_increment_id' => $reserved,
            'currency' => 'USD',
            'grand_total_cents' => 123456,
            'snapshot_hash' => str_repeat('1', 64),
            'state' => $state->value,
            'updated_at' => '2026-10-07 09:00:00',
            'expires_at' => '2026-10-07 10:00:00',
        ]);
    }
}
