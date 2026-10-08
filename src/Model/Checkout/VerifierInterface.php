<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Model\Checkout;

use Hyva\CheckoutAffirm\Model\Attempt\Attempt;

/**
 * Reads a checkout back from Affirm and compares it with the attempt. Writes nothing.
 */
interface VerifierInterface
{
    /**
     * Verify that Affirm confirmed exactly this attempt.
     *
     * Requires checkout_status "confirmed", order_id = reserved increment id, total = grand_total_cents and
     * currency = attempt currency. Anything missing, unexpected or unreachable fails closed; never throws.
     *
     * @param Attempt $attempt
     * @param string $checkoutToken
     * @return VerificationResult
     */
    public function verify(Attempt $attempt, #[\SensitiveParameter] string $checkoutToken): VerificationResult;
}
