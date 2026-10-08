<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Model\Checkout;

use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;

/**
 * Builds Affirm's checkout object from a quote whose totals are collected and whose order id is reserved.
 */
interface CheckoutObjectBuilderInterface
{
    public const MODE_MODAL = 'modal';
    public const MODE_REDIRECT = 'redirect';
    public const MODE_INLINE = 'inline';

    /**
     * Build the checkout object; a pure read that reserves and writes nothing.
     *
     * Unsupported carts (currency, adjustments, rounding) throw a LocalizedException subclass
     * (AccountingException) whose message is safe to show to the shopper.
     *
     * @param Quote $quote
     * @param string $mode self::MODE_MODAL|self::MODE_REDIRECT|self::MODE_INLINE
     * @param string $attemptId the attempt the return URLs point to; empty for MODE_INLINE
     * @return array<string, mixed> Affirm checkout object
     * @throws LocalizedException
     */
    public function build(Quote $quote, string $mode, #[\SensitiveParameter] string $attemptId): array;
}
