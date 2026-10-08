<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Model\Attempt;

/**
 * When an attempt in placing or authorizing counts as abandoned by the process that placed it.
 *
 * A placement takes seconds. An attempt that has stayed in placing or authorizing, unchanged, for the threshold was
 * left by a process that died (request timeout, out of memory, a PHP-FPM reload during a deployment). The one
 * threshold serves the shopper's return page, the escalation cron job and the resolve command's --force; change it
 * with the DI argument staleAfterSeconds of this class.
 */
class StaleAttemptPolicy
{
    public const DEFAULT_STALE_AFTER_SECONDS = 900;

    /**
     * Lower bound: a misconfigured threshold must never treat a running placement as abandoned.
     */
    private const MIN_STALE_AFTER_SECONDS = 60;

    /**
     * @param int $staleAfterSeconds how long a placing/authorizing attempt must be unchanged to count as abandoned
     */
    public function __construct(
        private readonly int $staleAfterSeconds = self::DEFAULT_STALE_AFTER_SECONDS
    ) {
    }

    /**
     * States a placement process holds an attempt in while it runs.
     *
     * @return list<State>
     */
    public static function states(): array
    {
        return [State::PLACING, State::AUTHORIZING];
    }

    /**
     * The threshold in seconds (at least one minute).
     *
     * @return int
     */
    public function staleAfterSeconds(): int
    {
        return max(self::MIN_STALE_AFTER_SECONDS, $this->staleAfterSeconds);
    }

    /**
     * Latest last-change time (Unix) at which an attempt counts as abandoned at $now.
     *
     * @param int $now Unix timestamp
     * @return int
     */
    public function cutoff(int $now): int
    {
        return $now - $this->staleAfterSeconds();
    }

    /**
     * Whether the attempt is placing or authorizing and has not changed for the threshold.
     *
     * An unreadable last-change time never counts as abandoned.
     *
     * @param Attempt $attempt
     * @param int $now Unix timestamp
     * @return bool
     */
    public function isStale(Attempt $attempt, int $now): bool
    {
        if (!in_array($attempt->state, self::states(), true)) {
            return false;
        }
        $updatedAt = $attempt->updatedAtTimestamp();

        return $updatedAt !== null && $updatedAt <= $this->cutoff($now);
    }
}
