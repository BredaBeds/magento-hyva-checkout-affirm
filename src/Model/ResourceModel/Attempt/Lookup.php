<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Model\ResourceModel\Attempt;

use Hyva\CheckoutAffirm\Model\Attempt\Attempt;
use Hyva\CheckoutAffirm\Model\Attempt\AttemptRepositoryInterface;
use Hyva\CheckoutAffirm\Model\Attempt\StaleAttemptPolicy;
use Hyva\CheckoutAffirm\Model\Attempt\State;
use Magento\Framework\App\ResourceConnection;

/**
 * Read-only queries for the operations tooling (CLI listing, cleanup and escalation cron jobs).
 *
 * State changes never happen here: callers pass the attempts they found to AttemptRepositoryInterface::transition(),
 * which compares and swaps the state each row was read in.
 */
class Lookup
{
    /**
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * Attempts in the given states, newest first.
     *
     * @param list<State> $states every state when empty
     * @param int $limit
     * @return list<Attempt>
     */
    public function findByStates(array $states, int $limit): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName(AttemptRepositoryInterface::TABLE))
            ->order(['created_at DESC', 'attempt_id ASC'])
            ->limit(max(1, $limit));
        if ($states !== []) {
            $select->where('state IN (?)', $this->values($states));
        }

        return $this->toAttempts($connection->fetchAll($select));
    }

    /**
     * Initiated or confirmed attempts that expired before the given time, oldest expiry first.
     *
     * @param int $expiredBefore Unix timestamp
     * @param int $limit
     * @return list<Attempt>
     */
    public function findExpiredOpen(int $expiredBefore, int $limit): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName(AttemptRepositoryInterface::TABLE))
            ->where('state IN (?)', $this->values(State::open()))
            ->where('expires_at < ?', gmdate(Attempt::DATE_FORMAT, $expiredBefore))
            ->order(['expires_at ASC', 'attempt_id ASC'])
            ->limit(max(1, $limit));

        return $this->toAttempts($connection->fetchAll($select));
    }

    /**
     * Placing or authorizing attempts last changed at or before the given time, oldest change first.
     *
     * @param int $changedBefore Unix timestamp (StaleAttemptPolicy::cutoff())
     * @param int $limit
     * @return list<Attempt>
     */
    public function findStale(int $changedBefore, int $limit): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName(AttemptRepositoryInterface::TABLE))
            ->where('state IN (?)', $this->values(StaleAttemptPolicy::states()))
            ->where('updated_at <= ?', gmdate(Attempt::DATE_FORMAT, $changedBefore))
            ->order(['updated_at ASC', 'attempt_id ASC'])
            ->limit(max(1, $limit));

        return $this->toAttempts($connection->fetchAll($select));
    }

    /**
     * Enum values for an IN (...) clause.
     *
     * @param list<State> $states
     * @return list<string>
     */
    private function values(array $states): array
    {
        return array_values(array_unique(array_map(static fn (State $state): string => $state->value, $states)));
    }

    /**
     * Database rows to attempts.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return list<Attempt>
     */
    private function toAttempts(array $rows): array
    {
        $attempts = [];
        foreach ($rows as $row) {
            $attempts[] = Attempt::fromRow($row);
        }

        return $attempts;
    }
}
