<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Model\Checkout;

/**
 * Outcome of reading an Affirm checkout back before placing the order. The observed values are kept for
 * diagnostics when the response was readable.
 */
final class VerificationResult // phpcs:ignore Magento2.PHP.FinalImplementation -- immutable value object
{
    public const REASON_INVALID_TOKEN = 'invalid_token';
    public const REASON_UNSUPPORTED_CURRENCY = 'unsupported_currency';
    public const REASON_NOT_CONFIGURED = 'not_configured';
    public const REASON_TRANSPORT_ERROR = 'transport_error';
    public const REASON_HTTP_ERROR = 'http_error';
    public const REASON_SERVICE_UNAVAILABLE = 'service_unavailable';
    public const REASON_INVALID_RESPONSE = 'invalid_response';
    public const REASON_MISSING_FIELD = 'missing_field';
    public const REASON_NOT_CONFIRMED = 'not_confirmed';
    public const REASON_ORDER_ID_MISMATCH = 'order_id_mismatch';
    public const REASON_TOTAL_MISMATCH = 'total_mismatch';
    public const REASON_CURRENCY_MISMATCH = 'currency_mismatch';

    /**
     * @param bool $ok
     * @param string $reason empty when ok, otherwise one of the REASON_* codes
     * @param string|null $checkoutStatus
     * @param string|null $orderId
     * @param int|null $total in cents
     * @param string|null $currency
     */
    public function __construct(
        public readonly bool $ok,
        public readonly string $reason = '',
        public readonly ?string $checkoutStatus = null,
        public readonly ?string $orderId = null,
        public readonly ?int $total = null,
        public readonly ?string $currency = null
    ) {
    }

    /**
     * Whether Affirm could not answer (unreachable or busy), so the same token may be verified again.
     *
     * @return bool
     */
    public function isTemporary(): bool
    {
        return in_array(
            $this->reason,
            [self::REASON_TRANSPORT_ERROR, self::REASON_SERVICE_UNAVAILABLE],
            true
        );
    }

    /**
     * A confirmed checkout matching the attempt.
     *
     * @param string $checkoutStatus
     * @param string $orderId
     * @param int $total
     * @param string $currency
     * @return self
     */
    public static function success(string $checkoutStatus, string $orderId, int $total, string $currency): self
    {
        return new self(true, '', $checkoutStatus, $orderId, $total, $currency);
    }

    /**
     * A failed verification (fail closed).
     *
     * @param string $reason
     * @param string|null $checkoutStatus
     * @param string|null $orderId
     * @param int|null $total
     * @param string|null $currency
     * @return self
     */
    public static function failure(
        string $reason,
        ?string $checkoutStatus = null,
        ?string $orderId = null,
        ?int $total = null,
        ?string $currency = null
    ): self {
        return new self(false, $reason, $checkoutStatus, $orderId, $total, $currency);
    }
}
