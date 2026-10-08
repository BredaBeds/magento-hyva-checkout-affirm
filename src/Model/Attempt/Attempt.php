<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Model\Attempt;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * One row of hyva_affirm_checkout_attempt (immutable; the repository re-reads after every transition).
 *
 * Timestamps are UTC strings in the database format ("Y-m-d H:i:s").
 */
final class Attempt // phpcs:ignore Magento2.PHP.FinalImplementation -- immutable value object
{
    public const ID_PATTERN = '/^[0-9a-f]{64}\z/';

    public const DATE_FORMAT = 'Y-m-d H:i:s';

    public const REASON_CANCELLED = 'cancelled';
    public const REASON_DECLINED = 'declined';
    public const REASON_EXPIRED = 'expired';
    public const REASON_VERIFICATION_FAILED = 'verification_failed';
    public const REASON_CART_CHANGED = 'cart_changed';
    public const REASON_UNAVAILABLE = 'unavailable';
    public const REASON_SUPERSEDED = 'superseded';
    public const REASON_PLACEMENT_FAILED = 'placement_failed';
    public const REASON_RESOLVED_PLACED = 'resolved_placed';
    public const REASON_RESOLVED_VOIDED = 'resolved_voided';

    private const REQUIRED_COLUMNS = [
        'attempt_id',
        'owner_key_hash',
        'quote_id',
        'store_id',
        'reserved_increment_id',
        'currency',
        'grand_total_cents',
        'snapshot_hash',
        'state',
        'expires_at',
    ];

    /**
     * @param string $attemptId
     * @param string $ownerKeyHash
     * @param int $quoteId
     * @param int $storeId
     * @param int|null $customerId
     * @param string $reservedIncrementId
     * @param string $currency
     * @param int $grandTotalCents
     * @param string $paymentMethod
     * @param string $snapshotHash
     * @param State $state
     * @param string|null $tokenHash
     * @param string|null $orderIncrementId
     * @param int|null $orderId
     * @param string|null $failureReason
     * @param string|null $providerTransactionId
     * @param string $createdAt
     * @param string $updatedAt
     * @param string $expiresAt
     */
    public function __construct(
        public readonly string $attemptId,
        public readonly string $ownerKeyHash,
        public readonly int $quoteId,
        public readonly int $storeId,
        public readonly ?int $customerId,
        public readonly string $reservedIncrementId,
        public readonly string $currency,
        public readonly int $grandTotalCents,
        public readonly string $paymentMethod,
        public readonly string $snapshotHash,
        public readonly State $state,
        public readonly ?string $tokenHash,
        public readonly ?string $orderIncrementId,
        public readonly ?int $orderId,
        public readonly ?string $failureReason,
        public readonly ?string $providerTransactionId,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        public readonly string $expiresAt
    ) {
    }

    /**
     * Whether the value has the shape of an attempt id (64 lowercase hex characters).
     *
     * @param string $attemptId
     * @return bool
     */
    public static function isValidId(#[\SensitiveParameter] string $attemptId): bool
    {
        return preg_match(self::ID_PATTERN, $attemptId) === 1;
    }

    /**
     * The value stored in token_hash for a checkout token.
     *
     * @param string $checkoutToken
     * @return string
     */
    public static function hashToken(#[\SensitiveParameter] string $checkoutToken): string
    {
        return hash('sha256', $checkoutToken);
    }

    /**
     * Build from a database row.
     *
     * @param array<string, mixed> $row
     * @return self
     * @throws InvalidArgumentException when a required column is missing
     * @throws UnexpectedValueException when the stored state is unknown
     */
    public static function fromRow(array $row): self
    {
        foreach (self::REQUIRED_COLUMNS as $column) {
            if (!isset($row[$column]) || !is_scalar($row[$column])) {
                throw new InvalidArgumentException(sprintf('Attempt row is missing column "%s".', $column));
            }
        }

        $state = State::tryFrom((string) $row['state']);
        if ($state === null) {
            throw new UnexpectedValueException('Attempt row has an unknown state.');
        }

        return new self(
            attemptId: (string) $row['attempt_id'],
            ownerKeyHash: (string) $row['owner_key_hash'],
            quoteId: (int) $row['quote_id'],
            storeId: (int) $row['store_id'],
            customerId: self::nullableInt($row['customer_id'] ?? null),
            reservedIncrementId: (string) $row['reserved_increment_id'],
            currency: (string) $row['currency'],
            grandTotalCents: (int) $row['grand_total_cents'],
            paymentMethod: (string) ($row['payment_method'] ?? 'affirm_gateway'),
            snapshotHash: (string) $row['snapshot_hash'],
            state: $state,
            tokenHash: self::nullableString($row['token_hash'] ?? null),
            orderIncrementId: self::nullableString($row['order_increment_id'] ?? null),
            orderId: self::nullableInt($row['order_id'] ?? null),
            failureReason: self::nullableString($row['failure_reason'] ?? null),
            providerTransactionId: self::nullableString($row['provider_transaction_id'] ?? null),
            createdAt: (string) ($row['created_at'] ?? ''),
            updatedAt: (string) ($row['updated_at'] ?? ''),
            expiresAt: (string) $row['expires_at']
        );
    }

    /**
     * Column => value, as stored.
     *
     * @return array<string, int|string|null>
     */
    public function toArray(): array
    {
        return [
            'attempt_id' => $this->attemptId,
            'owner_key_hash' => $this->ownerKeyHash,
            'quote_id' => $this->quoteId,
            'store_id' => $this->storeId,
            'customer_id' => $this->customerId,
            'reserved_increment_id' => $this->reservedIncrementId,
            'currency' => $this->currency,
            'grand_total_cents' => $this->grandTotalCents,
            'payment_method' => $this->paymentMethod,
            'snapshot_hash' => $this->snapshotHash,
            'state' => $this->state->value,
            'token_hash' => $this->tokenHash,
            'order_increment_id' => $this->orderIncrementId,
            'order_id' => $this->orderId,
            'failure_reason' => $this->failureReason,
            'provider_transaction_id' => $this->providerTransactionId,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'expires_at' => $this->expiresAt,
        ];
    }

    /**
     * Unix timestamp of expires_at, or null when it cannot be parsed.
     *
     * @return int|null
     */
    public function expiresAtTimestamp(): ?int
    {
        return self::timestamp($this->expiresAt);
    }

    /**
     * Unix timestamp of updated_at (the last change of the row), or null when it cannot be parsed.
     *
     * @return int|null
     */
    public function updatedAtTimestamp(): ?int
    {
        return self::timestamp($this->updatedAt);
    }

    /**
     * Whether the attempt is past its expiry at the given Unix time (an unreadable expiry counts as expired).
     *
     * @param int $now
     * @return bool
     */
    public function isExpired(int $now): bool
    {
        $expiresAt = $this->expiresAtTimestamp();

        return $expiresAt === null || $expiresAt <= $now;
    }

    /**
     * Constant-time comparison of the stored token hash with the hash of a checkout token.
     *
     * @param string $checkoutToken
     * @return bool
     */
    public function hasToken(#[\SensitiveParameter] string $checkoutToken): bool
    {
        return $this->tokenHash !== null && hash_equals($this->tokenHash, self::hashToken($checkoutToken));
    }

    /**
     * Unix timestamp of a stored UTC date, or null when it cannot be parsed.
     *
     * @param string $value
     * @return int|null
     */
    private static function timestamp(string $value): ?int
    {
        $date = DateTimeImmutable::createFromFormat('!' . self::DATE_FORMAT, $value, new DateTimeZone('UTC'));

        return $date === false ? null : $date->getTimestamp();
    }

    /**
     * Cast a nullable column to int.
     *
     * @param mixed $value
     * @return int|null
     */
    private static function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' || !is_scalar($value) ? null : (int) $value;
    }

    /**
     * Cast a nullable column to string.
     *
     * @param mixed $value
     * @return string|null
     */
    private static function nullableString(mixed $value): ?string
    {
        return $value === null || !is_scalar($value) ? null : (string) $value;
    }
}
