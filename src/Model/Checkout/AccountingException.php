<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Model\Checkout;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * The cart cannot be described to Affirm faithfully, so Affirm is declined before the shopper leaves the store.
 *
 * The message is always safe to show to the shopper. The reason code and the diagnostics are meant for the log
 * only and never carry customer data, tokens or attempt ids.
 */
class AccountingException extends LocalizedException
{
    public const REASON_ORDER_NOT_RESERVED = 'order_not_reserved';
    public const REASON_NOT_CONFIGURED = 'not_configured';
    public const REASON_UNSUPPORTED_CURRENCY = 'unsupported_currency';
    public const REASON_CURRENCY_MISMATCH = 'currency_mismatch';
    public const REASON_MULTISHIPPING = 'multishipping';
    public const REASON_ZERO_TOTAL = 'zero_total';
    public const REASON_UNSUPPORTED_ADJUSTMENT = 'unsupported_adjustment';
    public const REASON_UNEXPLAINED_DIFFERENCE = 'unexplained_difference';
    public const REASON_NO_ITEMS = 'no_items';
    public const REASON_NON_INTEGER_QTY = 'non_integer_qty';
    public const REASON_ITEMS_MISMATCH = 'items_mismatch';
    public const REASON_INCOMPLETE_ADDRESS = 'incomplete_address';
    public const REASON_MISSING_EMAIL = 'missing_email';
    public const REASON_MISSING_SHIPPING_METHOD = 'missing_shipping_method';
    public const REASON_FINANCING_PROGRAM = 'financing_program_unavailable';

    /**
     * Reasons the shopper can fix in the checkout form.
     */
    private const INCOMPLETE_CHECKOUT = [
        self::REASON_INCOMPLETE_ADDRESS,
        self::REASON_MISSING_EMAIL,
        self::REASON_MISSING_SHIPPING_METHOD,
    ];

    private const CURRENCY = [
        self::REASON_UNSUPPORTED_CURRENCY,
        self::REASON_CURRENCY_MISMATCH,
    ];

    /**
     * @param string $reason one of the REASON_* constants
     * @param Phrase|null $phrase shopper-safe message; a generic one per reason when null
     * @param array<string,scalar|list<scalar>> $diagnostics non-personal log details (total codes, cent differences)
     */
    public function __construct(
        private readonly string $reason,
        ?Phrase $phrase = null,
        private readonly array $diagnostics = []
    ) {
        parent::__construct($phrase ?? $this->defaultPhrase($reason));
    }

    /**
     * Machine-readable reason code.
     *
     * @return string
     */
    public function getReason(): string
    {
        return $this->reason;
    }

    /**
     * Non-personal details for the log line.
     *
     * @return array<string, scalar|list<scalar>>
     */
    public function getDiagnostics(): array
    {
        return $this->diagnostics;
    }

    /**
     * Context for the single log line written when a cart is declined.
     *
     * @return array<string, scalar|list<scalar>>
     */
    public function getLogContext(): array
    {
        return ['reason' => $this->reason] + $this->diagnostics;
    }

    /**
     * Generic shopper-facing message for a reason.
     *
     * @param string $reason
     * @return Phrase
     */
    private function defaultPhrase(string $reason): Phrase
    {
        if (in_array($reason, self::INCOMPLETE_CHECKOUT, true)) {
            return __('Please complete your contact, address and shipping details before paying with Affirm.');
        }
        if (in_array($reason, self::CURRENCY, true)) {
            return __('Affirm is not available for the currency of this cart.');
        }

        return __('Affirm is not available for this cart.');
    }
}
