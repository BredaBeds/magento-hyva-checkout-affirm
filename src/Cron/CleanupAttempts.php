<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Cron;

use Hyva\CheckoutAffirm\Model\Attempt\Attempt;
use Hyva\CheckoutAffirm\Model\Attempt\AttemptRepositoryInterface;
use Hyva\CheckoutAffirm\Model\Attempt\State;
use Hyva\CheckoutAffirm\Model\ResourceModel\Attempt\Lookup;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;

/**
 * Daily job hyva_affirm_attempt_cleanup.
 *
 * 1. Deletes failed/resolved attempts and expired initiated/confirmed attempts older than the retention
 *    (AttemptRepositoryInterface::prune(); unresolved and placed attempts are never deleted).
 * 2. Marks initiated/confirmed attempts past their expiry as failed (expired), so the stored state tells the truth.
 *    The confirmation pipeline refuses expired attempts anyway; the grace period keeps the job clear of a
 *    confirmation that started just before the expiry. No authorization exists for an open attempt.
 *
 * Deletion runs first so that long-expired rows are deleted rather than re-dated by the expiry step.
 */
class CleanupAttempts
{
    public const DEFAULT_RETENTION_DAYS = 90;

    /**
     * @param AttemptRepositoryInterface $attempts
     * @param Lookup $lookup
     * @param DateTime $dateTime
     * @param LoggerInterface $logger
     * @param int $retentionDays
     * @param int $expiryGraceSeconds
     * @param int $batchSize
     * @param int $maxBatches upper bound per run; what is left is handled the next day
     */
    public function __construct(
        private readonly AttemptRepositoryInterface $attempts,
        private readonly Lookup $lookup,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger,
        private readonly int $retentionDays = self::DEFAULT_RETENTION_DAYS,
        private readonly int $expiryGraceSeconds = 900,
        private readonly int $batchSize = 500,
        private readonly int $maxBatches = 20
    ) {
    }

    /**
     * Run both steps; a failing step does not stop the other.
     *
     * @return void
     * @throws LocalizedException when a step failed (details are logged), so the cron schedule shows the error
     */
    public function execute(): void
    {
        $failed = false;
        $deleted = 0;
        $expired = 0;

        try {
            $deleted = $this->attempts->prune($this->retentionDays);
        } catch (\Throwable $exception) {
            $failed = true;
            $this->logFailure('prune', $exception);
        }
        try {
            $expired = $this->expireStale();
        } catch (\Throwable $exception) {
            $failed = true;
            $this->logFailure('expire', $exception);
        }

        if ($deleted > 0 || $expired > 0) {
            $this->logSummary($deleted, $expired);
        }
        if ($failed) {
            throw new LocalizedException(__('The Affirm checkout attempt cleanup failed. See the log for details.'));
        }
    }

    /**
     * Fail open attempts that expired more than the grace period ago, each by compare-and-swap on its read state.
     *
     * @return int number of attempts marked as expired
     */
    private function expireStale(): int
    {
        $expiredBefore = (int) $this->dateTime->gmtTimestamp() - max(0, $this->expiryGraceSeconds);
        $batchSize = max(1, $this->batchSize);
        $expired = 0;

        for ($batch = 0; $batch < $this->maxBatches; $batch++) {
            $attempts = $this->lookup->findExpiredOpen($expiredBefore, $batchSize);
            foreach ($attempts as $attempt) {
                if ($this->attempts->transition(
                    $attempt->attemptId,
                    [$attempt->state],
                    State::FAILED,
                    ['failure_reason' => Attempt::REASON_EXPIRED]
                )) {
                    $expired++;
                }
            }
            if (count($attempts) < $batchSize) {
                break;
            }
        }

        return $expired;
    }

    /**
     * Report what the run changed; informational only.
     *
     * The work is already committed here, so a log that cannot be written (for example an unwritable
     * system.log) must not mark the run as failed.
     *
     * @param int $deleted
     * @param int $expired
     * @return void
     */
    private function logSummary(int $deleted, int $expired): void
    {
        try {
            $this->logger->info('Affirm checkout attempt cleanup finished.', [
                'deleted' => $deleted,
                'expired' => $expired,
            ]);
        } catch (\Throwable) {
            return;
        }
    }

    /**
     * Log a failed step without attempt ids or hashes (database errors may quote them).
     *
     * @param string $step
     * @param \Throwable $exception
     * @return void
     */
    private function logFailure(string $step, \Throwable $exception): void
    {
        $this->logger->error('Affirm checkout attempt cleanup: a step failed.', [
            'step' => $step,
            'exception_class' => get_class($exception),
            'message' => (string) preg_replace('/[0-9a-f]{64}/i', '[redacted]', $exception->getMessage()),
        ]);
    }
}
