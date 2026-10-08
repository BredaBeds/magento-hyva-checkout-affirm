<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Model\Attempt;

use Magento\Quote\Model\Quote;

/**
 * Fingerprint of everything Affirm approved: if it differs at confirmation, the cart changed after the
 * checkout object was sent and the loan must not be used for it.
 */
interface SnapshotInterface
{
    /**
     * SHA-256 of the canonical cart data. Callers collect totals first.
     *
     * @param Quote $quote
     * @return string 64 lowercase hex characters
     */
    public function hash(Quote $quote): string;
}
