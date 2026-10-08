<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Observer;

use Hyva\CheckoutAffirm\Model\Attempt\AttemptRepositoryInterface;
use Hyva\CheckoutAffirm\Model\Attempt\PlacementContext;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Psr\Log\LoggerInterface;

/**
 * Refuses to submit a cart while one of its Affirm attempts may hold an authorization (placing, authorizing or
 * uncertain), whatever the payment method: paying again could charge the shopper twice.
 *
 * Observes sales_model_service_quote_submit_before, which QuoteManagement dispatches for placeOrder() and submit()
 * (so also for REST, GraphQL, PayPal Express and admin orders), and checkout_type_multishipping_create_orders_single,
 * which Magento's multishipping checkout dispatches for each of its orders before paying them. Both carry the quote
 * and the order and run before any payment is authorized. Placement paths that dispatch neither are not covered.
 *
 * The one placement the confirmation pipeline is running in this request is let through, but only under the order
 * number the attempt was confirmed for: Magento reserves a new number when another order used the reserved one
 * meanwhile, and Affirm's checkout carries the reserved number, so such an order is refused before authorization.
 */
class BlockUnresolvedAttempts implements ObserverInterface
{
    /**
     * @param AttemptRepositoryInterface $attempts
     * @param PlacementContext $placementContext
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly AttemptRepositoryInterface $attempts,
        private readonly PlacementContext $placementContext,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Throw when the cart has another unresolved attempt or the pipeline's placement uses another order number.
     *
     * @param Observer $observer
     * @return void
     * @throws LocalizedException
     */
    public function execute(Observer $observer): void
    {
        $event = $observer->getEvent();
        $quote = $event->getData('quote');
        if (!$quote instanceof CartInterface) {
            return;
        }
        $quoteId = (int) $quote->getId();
        if ($quoteId <= 0) {
            return;
        }

        $placement = $this->placementContext->current();
        $exempt = null;
        if ($placement !== null && $placement['quote_id'] === $quoteId) {
            $this->assertReservedNumber($event->getData('order'), $placement['reserved_increment_id']);
            $exempt = $placement['attempt_id'];
        }

        try {
            $blocked = $this->attempts->hasUnresolvedForQuote($quoteId, $exempt);
        } catch (\Throwable $exception) {
            // Failing open: a read error must not stop every payment method on the store.
            $this->logger->error(
                'Affirm checkout: unresolved attempts could not be checked before order submission.',
                ['exception_class' => get_class($exception)]
            );

            return;
        }

        if ($blocked) {
            throw new LocalizedException(
                __('Payment for this cart is still being confirmed. Please wait a moment or contact us.')
            );
        }
    }

    /**
     * Refuse the pipeline's placement when the order is not submitted under the attempt's reserved number.
     *
     * @param mixed $order the event's order
     * @param string $reservedIncrementId '' when the placement did not say
     * @return void
     * @throws LocalizedException
     */
    private function assertReservedNumber(mixed $order, string $reservedIncrementId): void
    {
        if ($reservedIncrementId === '' || !$order instanceof OrderInterface) {
            return;
        }
        $submitted = (string) $order->getIncrementId();
        if ($submitted === $reservedIncrementId) {
            return;
        }

        $this->logger->warning(
            'Affirm checkout: the order would be placed under another number than the one Affirm confirmed;'
            . ' the placement is refused before authorization.',
            ['order_reference' => $reservedIncrementId, 'submitted_order_reference' => $submitted]
        );

        throw new LocalizedException(__(
            'The order number reserved for this Affirm payment is no longer available.'
            . ' Please start the payment again.'
        ));
    }
}
