<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Model\Checkout;

use InvalidArgumentException;

/**
 * Result of ConfirmationPipelineInterface::confirm(). Controllers and the place-order service turn it into
 * redirects or exceptions.
 *
 * $reason is a machine code (an Attempt::REASON_* code or one of the REASON_* codes below); $message is
 * already translated and safe to show to the shopper.
 */
final class ConfirmationOutcome // phpcs:ignore Magento2.PHP.FinalImplementation -- immutable value object
{
    public const PLACED = 'placed';
    public const IN_PROGRESS = 'in_progress';
    public const UNCERTAIN = 'uncertain';
    public const FAILED = 'failed';

    public const REASON_NOT_FOUND = 'not_found';
    public const REASON_NOT_OWNER = 'not_owner';
    public const REASON_IN_PROGRESS = 'in_progress';
    public const REASON_RETRY = 'retry';
    public const REASON_UNCERTAIN = 'uncertain';

    private const STATUSES = [self::PLACED, self::IN_PROGRESS, self::UNCERTAIN, self::FAILED];

    /**
     * @param string $status one of PLACED, IN_PROGRESS, UNCERTAIN, FAILED
     * @param int|null $orderId
     * @param string|null $incrementId
     * @param int|null $quoteId
     * @param string $reason
     * @param string $message
     * @param bool $replay true when the order was placed by an earlier request
     */
    public function __construct(
        public readonly string $status,
        public readonly ?int $orderId = null,
        public readonly ?string $incrementId = null,
        public readonly ?int $quoteId = null,
        public readonly string $reason = '',
        public readonly string $message = '',
        public readonly bool $replay = false
    ) {
        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException(sprintf('Unknown confirmation status "%s".', $status));
        }
        if ($status === self::PLACED && ($orderId === null || $orderId <= 0 || (string) $incrementId === '')) {
            throw new InvalidArgumentException('A placed outcome needs the order id and increment id.');
        }
    }

    /**
     * The order exists (placed now, or by an earlier request when $replay is true).
     *
     * @param int $orderId
     * @param string $incrementId
     * @param int $quoteId
     * @param bool $replay
     * @return self
     */
    public static function placed(int $orderId, string $incrementId, int $quoteId, bool $replay = false): self
    {
        return new self(self::PLACED, $orderId, $incrementId, $quoteId, '', '', $replay);
    }

    /**
     * Another request owns the attempt, or the cart was locked: try again shortly.
     *
     * @param string $message
     * @param int|null $quoteId
     * @param string $reason
     * @return self
     */
    public static function inProgress(
        string $message,
        ?int $quoteId = null,
        string $reason = self::REASON_IN_PROGRESS
    ): self {
        return new self(self::IN_PROGRESS, null, null, $quoteId, $reason, $message);
    }

    /**
     * Affirm may have authorized but no order was found; the attempt needs reconciliation.
     *
     * @param string $message
     * @param int|null $quoteId
     * @param string|null $incrementId the reserved order number, for the shopper's reference
     * @return self
     */
    public static function uncertain(string $message, ?int $quoteId = null, ?string $incrementId = null): self
    {
        return new self(self::UNCERTAIN, null, $incrementId, $quoteId, self::REASON_UNCERTAIN, $message);
    }

    /**
     * No order was or will be placed for this attempt.
     *
     * @param string $reason
     * @param string $message
     * @param int|null $quoteId
     * @return self
     */
    public static function failed(string $reason, string $message, ?int $quoteId = null): self
    {
        return new self(self::FAILED, null, null, $quoteId, $reason, $message);
    }

    /**
     * Whether the order exists.
     *
     * @return bool
     */
    public function isPlaced(): bool
    {
        return $this->status === self::PLACED;
    }

    /**
     * Whether another request is still working on the attempt.
     *
     * @return bool
     */
    public function isInProgress(): bool
    {
        return $this->status === self::IN_PROGRESS;
    }

    /**
     * Whether the attempt awaits reconciliation.
     *
     * @return bool
     */
    public function isUncertain(): bool
    {
        return $this->status === self::UNCERTAIN;
    }

    /**
     * Whether the attempt definitively failed.
     *
     * @return bool
     */
    public function isFailed(): bool
    {
        return $this->status === self::FAILED;
    }
}
