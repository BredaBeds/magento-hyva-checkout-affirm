<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Model\Attempt;

use Magento\Framework\Notification\NotifierInterface;
use Psr\Log\LoggerInterface;

/**
 * Admin inbox message for attempts that need manual reconciliation.
 *
 * Goes through Magento's notifier pool, which writes to the admin inbox (Magento_AdminNotification) when that
 * module is enabled. The message names the order reference only: never tokens, attempt ids or customer data.
 */
class AdminNotifier
{
    /**
     * @param NotifierInterface $notifier
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly NotifierInterface $notifier,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Report an attempt for which Affirm may hold an authorization but no order was found.
     *
     * @param string $orderReference the reserved order increment id
     * @return void
     */
    public function notifyUncertain(string $orderReference): void
    {
        try {
            $this->notifier->addMajor(
                (string) __('Affirm payment needs review (order #%1)', $orderReference),
                (string) __(
                    'Affirm may have authorized the payment for order #%1, but the order could not be created.'
                    . ' Look up the transaction in the Affirm merchant dashboard, then resolve the checkout attempt'
                    . ' with bin/magento hyva:affirm:attempt:list and hyva:affirm:attempt:resolve: void it at'
                    . ' Affirm if no order exists. The cart stays blocked for other payments until it is resolved.',
                    $orderReference
                )
            );
        } catch (\Throwable $exception) {
            $this->logger->error(
                'Affirm checkout: the admin notification for an attempt that needs review could not be added.',
                ['exception_class' => get_class($exception), 'order_reference' => $orderReference]
            );
        }
    }
}
