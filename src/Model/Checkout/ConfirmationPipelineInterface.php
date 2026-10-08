<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Model\Checkout;

use Magento\Quote\Model\Quote;

/**
 * Turns a checkout token Affirm returned for an attempt into an order, at most once (both checkout modes).
 */
interface ConfirmationPipelineInterface
{
    /**
     * Place the order for a confirmed Affirm checkout.
     *
     * Resolves the attempt, checks ownership and the cart, verifies at Affirm, claims the attempt and places the
     * order. Outcomes are returned, not thrown; messages never contain the token or the attempt id.
     *
     * @param string $attemptId
     * @param string $checkoutToken
     * @param Quote|null $quote the request's quote instance (modal mode); null loads it by the attempt's quote id
     * @return ConfirmationOutcome
     */
    public function confirm(
        #[\SensitiveParameter] string $attemptId,
        #[\SensitiveParameter] string $checkoutToken,
        ?Quote $quote = null
    ): ConfirmationOutcome;
}
