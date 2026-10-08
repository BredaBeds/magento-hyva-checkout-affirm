<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Cron;

use Hyva\CheckoutAffirm\Model\Attempt\StaleAttemptPolicy;
use Hyva\CheckoutAffirm\Model\Checkout\AttemptRecovery;
use Hyva\CheckoutAffirm\Model\ResourceModel\Attempt\Lookup;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;

/**
 * Job hyva_affirm_attempt_escalate (every 5 minutes).
 *
 * Settles attempts left in placing or authorizing by a process that died mid-placement (unchanged for the
 * StaleAttemptPolicy threshold): linked to their order when one provably belongs to them, otherwise moved to
 * uncertain with a CRITICAL log line and an admin inbox message, so the merchant reconciles them and the cart stops
 * being blocked without anyone noticing. Each row is changed by compare-and-swap on the row as read, so the job is
 * idempotent and never touches a placement that is still running. One batch per run; the rest waits for the next run.
 */
class EscalateStaleAttempts
{
    /**
     * @param Lookup $lookup
     * @param AttemptRecovery $recovery
     * @param StaleAttemptPolicy $stalePolicy
     * @param DateTime $dateTime
     * @param LoggerInterface $logger
     * @param int $batchSize attempts handled per run
     */
    public function __construct(
        private readonly Lookup $lookup,
        private readonly AttemptRecovery $recovery,
        private readonly StaleAttemptPolicy $stalePolicy,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger,
        private readonly int $batchSize = 50
    ) {
    }

    /**
     * Escalate one batch of stale attempts; a failing attempt does not stop the others.
     *
     * @return void
     * @throws LocalizedException when an attempt could not be handled (details are logged), so the cron schedule
     *         shows the error
     */
    public function execute(): void
    {
        $now = (int) $this->dateTime->gmtTimestamp();
        try {
            $attempts = $this->lookup->findStale($this->stalePolicy->cutoff($now), max(1, $this->batchSize));
        } catch (\Throwable $exception) {
            $this->logFailure($exception, null);

            throw new LocalizedException(
                __('Stale Affirm checkout attempts could not all be escalated. See the log for details.')
            );
        }

        $counts = [AttemptRecovery::ESCALATED_PLACED => 0, AttemptRecovery::ESCALATED_UNCERTAIN => 0];
        $failed = false;
        foreach ($attempts as $attempt) {
            try {
                $result = $this->recovery->escalate($attempt, $now);
            } catch (\Throwable $exception) {
                $failed = true;
                $this->logFailure($exception, $attempt->reservedIncrementId);
                continue;
            }
            if (isset($counts[$result])) {
                $counts[$result]++;
            }
        }

        if (array_sum($counts) > 0) {
            $this->logSummary($counts);
        }
        if ($failed) {
            throw new LocalizedException(
                __('Stale Affirm checkout attempts could not all be escalated. See the log for details.')
            );
        }
    }

    /**
     * Report what the run changed; informational only (a log that cannot be written must not fail the run).
     *
     * @param array<string, int> $counts
     * @return void
     */
    private function logSummary(array $counts): void
    {
        try {
            $this->logger->info('Affirm checkout: stale attempts escalated.', $counts);
        } catch (\Throwable) {
            return;
        }
    }

    /**
     * Log a failure with the exception class and the order reference only (database errors may quote attempt ids).
     *
     * @param \Throwable $exception
     * @param string|null $orderReference
     * @return void
     */
    private function logFailure(\Throwable $exception, ?string $orderReference): void
    {
        $context = ['exception_class' => get_class($exception)];
        if ($orderReference !== null) {
            $context['order_reference'] = $orderReference;
        }
        $this->logger->error('Affirm checkout: a stale attempt could not be escalated.', $context);
    }
}
