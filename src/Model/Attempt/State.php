<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Model\Attempt;

/**
 * Checkout attempt states.
 *
 * initiated -> confirmed -> placing -> authorizing -> placed | uncertain
 * placing -> placed | confirmed (cart locked, retry) | failed
 * placing -> uncertain (escalated: the placing process died; see StaleAttemptPolicy)
 * authorizing -> placing (Affirm definitely refused the authorization)
 * initiated|confirmed -> failed
 * uncertain -> resolved (reconciliation; placing/authorizing too when a process died mid-placement)
 */
enum State: string
{
    case INITIATED = 'initiated';
    case CONFIRMED = 'confirmed';
    case PLACING = 'placing';
    case AUTHORIZING = 'authorizing';
    case PLACED = 'placed';
    case FAILED = 'failed';
    case UNCERTAIN = 'uncertain';
    case RESOLVED = 'resolved';

    /**
     * States that may still be confirmed, cancelled or expired by the shopper's flow.
     *
     * @return list<self>
     */
    public static function open(): array
    {
        return [self::INITIATED, self::CONFIRMED];
    }

    /**
     * States in which Affirm may hold an authorization without a known order.
     *
     * Other payments on the cart are blocked while an attempt is in one of them.
     *
     * @return list<self>
     */
    public static function unresolved(): array
    {
        return [self::PLACING, self::AUTHORIZING, self::UNCERTAIN];
    }

    /**
     * Whether the state machine allows moving from this state to $target.
     *
     * @param State $target
     * @return bool
     */
    public function canTransitionTo(self $target): bool
    {
        $allowed = match ($this) {
            self::INITIATED => [self::CONFIRMED, self::FAILED],
            self::CONFIRMED => [self::PLACING, self::FAILED],
            self::PLACING => [
                self::AUTHORIZING,
                self::PLACED,
                self::CONFIRMED,
                self::FAILED,
                self::UNCERTAIN,
                self::RESOLVED,
            ],
            self::AUTHORIZING => [self::PLACED, self::UNCERTAIN, self::PLACING, self::RESOLVED],
            self::UNCERTAIN => [self::RESOLVED],
            self::PLACED, self::FAILED, self::RESOLVED => [],
        };

        return in_array($target, $allowed, true);
    }

    /**
     * Whether no transition leaves this state.
     *
     * @return bool
     */
    public function isFinal(): bool
    {
        return in_array($this, [self::PLACED, self::FAILED, self::RESOLVED], true);
    }

    /**
     * Whether the attempt is still open (initiated or confirmed).
     *
     * @return bool
     */
    public function isOpen(): bool
    {
        return in_array($this, self::open(), true);
    }

    /**
     * Whether the attempt blocks other payments on its cart.
     *
     * @return bool
     */
    public function isUnresolved(): bool
    {
        return in_array($this, self::unresolved(), true);
    }
}
