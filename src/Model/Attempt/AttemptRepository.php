<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Model\Attempt;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Attempt storage on the default connection. Writes are single statements so they commit immediately
 * (no transaction is open around payment authorization in Magento's placement).
 */
class AttemptRepository implements AttemptRepositoryInterface
{
    /**
     * Columns a transition may write besides state.
     */
    private const MUTABLE_COLUMNS = [
        'token_hash',
        'order_increment_id',
        'order_id',
        'failure_reason',
        'provider_transaction_id',
        'expires_at',
    ];

    /**
     * Columns a transition may add to its WHERE clause besides attempt_id and state.
     */
    private const CONDITION_COLUMNS = [
        'owner_key_hash',
        'quote_id',
        'store_id',
        'customer_id',
        'reserved_increment_id',
        'currency',
        'grand_total_cents',
        'payment_method',
        'snapshot_hash',
        'token_hash',
        'order_increment_id',
        'order_id',
        'failure_reason',
        'provider_transaction_id',
        'updated_at',
    ];

    /**
     * @param ResourceConnection $resource
     * @param DateTime $dateTime
     * @param int $lifetimeSeconds how long an initiated/confirmed attempt stays usable
     * @param int $reuseMinRemainingSeconds an attempt is reused only with at least this much lifetime left
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly DateTime $dateTime,
        private readonly int $lifetimeSeconds = 3600,
        private readonly int $reuseMinRemainingSeconds = 900
    ) {
    }

    /**
     * @inheritdoc
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
    ): Attempt {
        if ($quoteId <= 0 || $reservedIncrementId === '' || $snapshotHash === '' || $ownerKeyHash === '') {
            throw new InvalidArgumentException(
                'An attempt needs a quote, a reserved order id, a snapshot and an owner.'
            );
        }

        $now = $this->now();
        $attempt = new Attempt(
            attemptId: bin2hex(random_bytes(32)),
            ownerKeyHash: $ownerKeyHash,
            quoteId: $quoteId,
            storeId: $storeId,
            customerId: $customerId,
            reservedIncrementId: $reservedIncrementId,
            currency: strtoupper($currency),
            grandTotalCents: $grandTotalCents,
            paymentMethod: $paymentMethod,
            snapshotHash: $snapshotHash,
            state: State::INITIATED,
            tokenHash: null,
            orderIncrementId: null,
            orderId: null,
            failureReason: null,
            providerTransactionId: null,
            createdAt: $this->format($now),
            updatedAt: $this->format($now),
            expiresAt: $this->format($now + $this->lifetimeSeconds)
        );
        $this->connection()->insert($this->table(), $attempt->toArray());

        return $attempt;
    }

    /**
     * @inheritdoc
     */
    public function get(#[\SensitiveParameter] string $attemptId): ?Attempt
    {
        if (!Attempt::isValidId($attemptId)) {
            return null;
        }
        $connection = $this->connection();
        $select = $connection->select()
            ->from($this->table())
            ->where('attempt_id = ?', $attemptId)
            ->limit(1);

        return $this->toAttempt($connection->fetchRow($select));
    }

    /**
     * @inheritdoc
     */
    public function supersedeOthersForQuote(int $quoteId, #[\SensitiveParameter] string $keepAttemptId): int
    {
        // One set-based compare-and-swap: parallel preparations cannot leave two open attempts behind.
        return $this->connection()->update(
            $this->table(),
            ['state' => State::FAILED->value, 'failure_reason' => Attempt::REASON_SUPERSEDED],
            [
                'quote_id = ?' => $quoteId,
                'state IN (?)' => $this->values(State::open()),
                'attempt_id <> ?' => $keepAttemptId,
            ]
        );
    }

    /**
     * @inheritdoc
     */
    public function findReusable(int $quoteId, string $ownerKeyHash, string $snapshotHash): ?Attempt
    {
        $connection = $this->connection();
        $select = $connection->select()
            ->from($this->table())
            ->where('quote_id = ?', $quoteId)
            ->where('owner_key_hash = ?', $ownerKeyHash)
            ->where('snapshot_hash = ?', $snapshotHash)
            ->where('state = ?', State::INITIATED->value)
            ->where('expires_at > ?', $this->format($this->now() + $this->reuseMinRemainingSeconds))
            ->order('created_at DESC')
            ->limit(1);

        return $this->toAttempt($connection->fetchRow($select));
    }

    /**
     * @inheritdoc
     */
    public function countRecentForQuote(int $quoteId, DateTimeInterface $since): int
    {
        $connection = $this->connection();
        $select = $connection->select()
            ->from($this->table(), ['attempts' => new Expression('COUNT(*)')])
            ->where('quote_id = ?', $quoteId)
            ->where('created_at >= ?', $this->formatDate($since));

        return (int) $connection->fetchOne($select);
    }

    /**
     * @inheritdoc
     */
    public function transition(
        #[\SensitiveParameter] string $attemptId,
        array $fromStates,
        State|string $toState,
        array $set = [],
        array $conditions = []
    ): bool {
        $target = $this->state($toState);
        $sources = [];
        foreach ($fromStates as $fromState) {
            $source = $this->state($fromState);
            if ($source === $target) {
                // A CAS that keeps the state cannot report success reliably (MySQL counts changed rows only).
                throw new InvalidArgumentException('The target state must differ from every source state.');
            }
            if (!$source->canTransitionTo($target)) {
                throw new InvalidArgumentException(
                    sprintf('Attempt transition "%s" -> "%s" is not allowed.', $source->value, $target->value)
                );
            }
            $sources[$source->value] = $source->value;
        }
        if ($sources === []) {
            throw new InvalidArgumentException('At least one source state is required.');
        }
        if (!Attempt::isValidId($attemptId)) {
            return false;
        }

        $bind = $this->bindValues($set, self::MUTABLE_COLUMNS);
        $bind['state'] = $target->value;

        $where = [
            'attempt_id = ?' => $attemptId,
            'state IN (?)' => array_values($sources),
        ];
        foreach ($this->bindValues($conditions, self::CONDITION_COLUMNS) as $column => $value) {
            if ($value === null) {
                $where[] = $column . ' IS NULL';
            } else {
                $where[$column . ' = ?'] = $value;
            }
        }

        return $this->connection()->update($this->table(), $bind, $where) === 1;
    }

    /**
     * @inheritdoc
     */
    public function recordTransactionId(#[\SensitiveParameter] string $attemptId, string $transactionId): bool
    {
        if (!Attempt::isValidId($attemptId) || $transactionId === '' || strlen($transactionId) > 64) {
            return false;
        }

        return $this->connection()->update(
            $this->table(),
            ['provider_transaction_id' => $transactionId],
            ['attempt_id = ?' => $attemptId, 'state = ?' => State::AUTHORIZING->value]
        ) === 1;
    }

    /**
     * @inheritdoc
     */
    public function hasUnresolvedForQuote(
        int $quoteId,
        #[\SensitiveParameter] ?string $exceptAttemptId = null
    ): bool {
        $connection = $this->connection();
        $select = $connection->select()
            ->from($this->table(), ['attempt_id'])
            ->where('quote_id = ?', $quoteId)
            ->where('state IN (?)', $this->values(State::unresolved()))
            ->limit(1);
        if ($exceptAttemptId !== null) {
            $select->where('attempt_id <> ?', $exceptAttemptId);
        }
        $found = $connection->fetchOne($select);

        return $found !== false && $found !== null;
    }

    /**
     * @inheritdoc
     */
    public function listUnresolved(int $limit = 200): array
    {
        $connection = $this->connection();
        $select = $connection->select()
            ->from($this->table())
            ->where('state IN (?)', $this->values(State::unresolved()))
            ->order('created_at ASC')
            ->limit(max(1, $limit));

        $attempts = [];
        foreach ($connection->fetchAll($select) as $row) {
            $attempts[] = Attempt::fromRow($row);
        }

        return $attempts;
    }

    /**
     * @inheritdoc
     */
    public function prune(int $olderThanDays): int
    {
        if ($olderThanDays < 1) {
            throw new InvalidArgumentException('Attempts can only be pruned after at least one day.');
        }
        $cutoff = $this->format($this->now() - $olderThanDays * 86400);
        $connection = $this->connection();

        $deleted = $connection->delete($this->table(), [
            'state IN (?)' => [State::FAILED->value, State::RESOLVED->value],
            'updated_at < ?' => $cutoff,
        ]);
        $deleted += $connection->delete($this->table(), [
            'state IN (?)' => $this->values(State::open()),
            'expires_at < ?' => $cutoff,
        ]);

        return $deleted;
    }

    /**
     * Default connection.
     *
     * @return AdapterInterface
     */
    private function connection(): AdapterInterface
    {
        return $this->resource->getConnection();
    }

    /**
     * Table name with the installation's prefix.
     *
     * @return string
     */
    private function table(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }

    /**
     * Current Unix time (UTC).
     *
     * @return int
     */
    private function now(): int
    {
        return (int) $this->dateTime->gmtTimestamp();
    }

    /**
     * Database timestamp for a Unix time (the connection runs in UTC).
     *
     * @param int $timestamp
     * @return string
     */
    private function format(int $timestamp): string
    {
        return gmdate(Attempt::DATE_FORMAT, $timestamp);
    }

    /**
     * Database timestamp for a date in any timezone.
     *
     * @param DateTimeInterface $date
     * @return string
     */
    private function formatDate(DateTimeInterface $date): string
    {
        return DateTimeImmutable::createFromInterface($date)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format(Attempt::DATE_FORMAT);
    }

    /**
     * Resolve a state given as enum or string.
     *
     * @param State|string $state
     * @return State
     */
    private function state(State|string $state): State
    {
        if ($state instanceof State) {
            return $state;
        }
        $resolved = State::tryFrom($state);
        if ($resolved === null) {
            throw new InvalidArgumentException(sprintf('Unknown attempt state "%s".', $state));
        }

        return $resolved;
    }

    /**
     * Validate column names and normalise values for the adapter.
     *
     * @param array<string, mixed> $values
     * @param list<string> $allowedColumns
     * @return array<string, int|float|string|null>
     */
    private function bindValues(array $values, array $allowedColumns): array
    {
        $bind = [];
        foreach ($values as $column => $value) {
            if (!in_array($column, $allowedColumns, true)) {
                throw new InvalidArgumentException(sprintf('Column "%s" cannot be used here.', $column));
            }
            if ($value instanceof DateTimeInterface) {
                $value = $this->formatDate($value);
            } elseif (is_bool($value)) {
                $value = (int) $value;
            } elseif ($value !== null && !is_scalar($value)) {
                throw new InvalidArgumentException(sprintf('Column "%s" needs a scalar value.', $column));
            }
            $bind[$column] = $value;
        }

        return $bind;
    }

    /**
     * Enum values for an IN (...) clause.
     *
     * @param list<State> $states
     * @return list<string>
     */
    private function values(array $states): array
    {
        return array_map(static fn (State $state): string => $state->value, $states);
    }

    /**
     * Row or false from fetchRow() to an attempt.
     *
     * @param mixed $row
     * @return Attempt|null
     */
    private function toAttempt(mixed $row): ?Attempt
    {
        return is_array($row) && $row !== [] ? Attempt::fromRow($row) : null;
    }
}
