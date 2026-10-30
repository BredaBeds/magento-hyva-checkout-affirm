<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Model\Attempt;

use DateTimeInterface;
use InvalidArgumentException;

/**
 * Storage for checkout attempts. Every state change is a compare-and-swap (transition()).
 */
interface AttemptRepositoryInterface
{
    public const TABLE = 'hyva_affirm_checkout_attempt';

    public const OUTCOME_PLACED = 'placed';
    public const OUTCOME_VOIDED = 'voided';

    /**
     * Insert a new attempt in state "initiated" with a random id; expires after the configured lifetime.
     *
     * @param int $quoteId
     * @param int $storeId
     * @param int|null $customerId
     * @param string $reservedIncrementId
     * @param string $currency
     * @param int $grandTotalCents
     * @param string $snapshotHash
     * @param string $ownerKeyHash
     * @param string $paymentMethod
     * @return Attempt
     */
    public function create(
        int $quoteId,
        int $storeId,
        ?int $customerId,
        string $reservedIncrementId,
        string $currency,
        int $grandTotalCents,
        string $snapshotHash,
        string $ownerKeyHash,
        string $paymentMethod = 'affirm_gateway'
    ): Attempt;

    /**
     * Load an attempt; null when the id is malformed or unknown.
     *
     * @param string $attemptId
     * @return Attempt|null
     */
    public function get(#[\SensitiveParameter] string $attemptId): ?Attempt;

    /**
     * Fail every other open attempt of a quote as superseded, so only the newest preparation can be confirmed.
     *
     * @param int $quoteId
     * @param string $keepAttemptId the attempt that stays open
     * @return int the number of attempts superseded
     */
    public function supersedeOthersForQuote(int $quoteId, #[\SensitiveParameter] string $keepAttemptId): int;

    /**
     * The latest reusable "initiated" attempt of this quote, owner and cart snapshot.
     *
     * Only attempts with enough lifetime left for a trip to Affirm are returned.
     *
     * @param int $quoteId
     * @param string $ownerKeyHash
     * @param string $snapshotHash
     * @return Attempt|null
     */
    public function findReusable(int $quoteId, string $ownerKeyHash, string $snapshotHash): ?Attempt;

    /**
     * Number of attempts created for a quote since the given time (throttling).
     *
     * @param int $quoteId
     * @param DateTimeInterface $since
     * @return int
     */
    public function countRecentForQuote(int $quoteId, DateTimeInterface $since): int;

    /**
     * Compare-and-swap: UPDATE ... SET state = $toState [, $set] WHERE attempt_id = ? AND state IN ($fromStates)
     * [AND each $conditions column = value]. True only when exactly this call changed the row.
     *
     * $set accepts token_hash, order_increment_id, order_id, failure_reason, provider_transaction_id and
     * expires_at (string or DateTimeInterface). $conditions accepts any column except attempt_id, state,
     * created_at and expires_at; a null value means IS NULL. A condition on updated_at changes only the row as it
     * was read (every write refreshes updated_at).
     *
     * @param string $attemptId
     * @param array<State|string> $fromStates
     * @param State|string $toState
     * @param array<string, mixed> $set
     * @param array<string, mixed> $conditions
     * @return bool
     * @throws InvalidArgumentException for unknown states/columns, an empty $fromStates, $toState in $fromStates
     *         or a transition State::canTransitionTo() does not allow
     */
    public function transition(
        #[\SensitiveParameter] string $attemptId,
        array $fromStates,
        State|string $toState,
        array $set = [],
        array $conditions = []
    ): bool;

    /**
     * Store Affirm's transaction id on an attempt that is authorizing (the state does not change).
     *
     * @param string $attemptId
     * @param string $transactionId at most 64 characters
     * @return bool false when the attempt is not authorizing, the id is invalid or the value was already stored
     */
    public function recordTransactionId(#[\SensitiveParameter] string $attemptId, string $transactionId): bool;

    /**
     * Whether the quote has an attempt in placing, authorizing or uncertain (other than $exceptAttemptId).
     *
     * @param int $quoteId
     * @param string|null $exceptAttemptId
     * @return bool
     */
    public function hasUnresolvedForQuote(
        int $quoteId,
        #[\SensitiveParameter] ?string $exceptAttemptId = null
    ): bool;

    /**
     * Attempts in placing, authorizing or uncertain, oldest first.
     *
     * @param int $limit
     * @return list<Attempt>
     */
    public function listUnresolved(int $limit = 200): array;

    /**
     * Delete old finished and expired attempts.
     *
     * Removes failed/resolved rows not updated for $olderThanDays days and initiated/confirmed rows that expired
     * more than $olderThanDays days ago. Never touches placing, authorizing, uncertain or placed rows.
     *
     * @param int $olderThanDays at least 1
     * @return int number of deleted rows
     * @throws InvalidArgumentException when $olderThanDays < 1
     */
    public function prune(int $olderThanDays): int;
}
