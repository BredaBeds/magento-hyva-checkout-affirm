<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Model\Checkout;

use Magento\Checkout\Helper\Data as CheckoutHelper;
use Magento\Quote\Model\Quote;

/**
 * Whether Magento lets a quote be placed: by its customer, or as a guest where the store allows guest checkout
 * for the cart ("Allow Guest Checkout", and the checkout_allow_guest event, which refuses carts with downloadable
 * products by default).
 *
 * Magento places a quote without a customer as a guest order, and the redirect return places the order outside
 * Hyvä's Place Order request (which refuses such quotes since Hyvä Checkout 3), so this module checks it itself.
 */
class GuestCheckout
{
    /**
     * @param CheckoutHelper $checkoutHelper
     */
    public function __construct(
        private readonly CheckoutHelper $checkoutHelper
    ) {
    }

    /**
     * Whether the quote may be placed.
     *
     * @param Quote $quote
     * @return bool
     */
    public function isAllowed(Quote $quote): bool
    {
        return self::customerId($quote) !== null || (bool) $this->checkoutHelper->isAllowedGuestCheckout($quote);
    }

    /**
     * The quote's customer id, or null for a guest quote.
     *
     * @param Quote $quote
     * @return int|null
     */
    public static function customerId(Quote $quote): ?int
    {
        $customerId = $quote->getData('customer_id');

        return is_numeric($customerId) && (int) $customerId > 0 ? (int) $customerId : null;
    }
}
