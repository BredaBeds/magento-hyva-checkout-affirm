<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Model\Checkout;

use Hyva\Checkout\Model\Config as HyvaCheckoutConfig;
use Magento\Framework\Controller\Result\Redirect;

/**
 * Where a shopper returning from Affirm without an order continues: the payment step of the active Hyvä
 * checkout (checkout?step=payment), or the checkout itself when the active checkout has no "payment" step or is
 * not a Hyvä checkout.
 */
class PaymentStep
{
    public const ROUTE = 'payment';

    /**
     * @param HyvaCheckoutConfig $checkoutConfig
     */
    public function __construct(
        private readonly HyvaCheckoutConfig $checkoutConfig
    ) {
    }

    /**
     * Point a redirect at the payment step.
     *
     * @param Redirect $redirect
     * @return Redirect
     */
    public function applyTo(Redirect $redirect): Redirect
    {
        $params = $this->hasPaymentStep() ? ['_query' => ['step' => self::ROUTE]] : [];
        $redirect->setPath('checkout', $params);

        return $redirect;
    }

    /**
     * Whether the active checkout has a step routed as "payment".
     *
     * @return bool
     */
    public function hasPaymentStep(): bool
    {
        try {
            $steps = $this->checkoutConfig->getActiveCheckoutData()['steps'] ?? [];
        } catch (\Throwable) {
            // Not a Hyvä checkout (CheckoutNotFoundException) or unreadable configuration.
            return false;
        }
        if (!is_array($steps)) {
            return false;
        }
        foreach ($steps as $step) {
            if (is_array($step) && ($step['route'] ?? null) === self::ROUTE) {
                return true;
            }
        }

        return false;
    }
}
