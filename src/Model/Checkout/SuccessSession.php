<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Model\Checkout;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Prepares the checkout session for Magento's success page (checkout/onepage/success).
 *
 * Magento's placement sets these five fields itself; they are set here when the order was placed by another
 * request (a replayed return), when the placement was interrupted after the order was saved, and on the redirect
 * return. The success controller requires LastSuccessQuoteId, LastQuoteId and LastOrderId and clears the quote.
 * Every armed success page re-dispatches checkout_onepage_controller_success_action (analytics observers must
 * de-duplicate by order id).
 */
class SuccessSession
{
    /**
     * @param CheckoutSession $checkoutSession
     * @param OrderRepositoryInterface $orderRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Set the success-page fields.
     *
     * @param int $quoteId
     * @param int $orderId
     * @param string $incrementId
     * @param string|null $orderStatus
     * @return void
     */
    public function arm(int $quoteId, int $orderId, string $incrementId, ?string $orderStatus = null): void
    {
        // The same magic setters as QuoteManagement::placeOrder() (SessionManager forwards them to its storage).
        $session = $this->checkoutSession;
        $session->setLastQuoteId($quoteId); // @phpstan-ignore method.notFound
        $session->setLastSuccessQuoteId($quoteId); // @phpstan-ignore method.notFound
        $session->setLastOrderId($orderId); // @phpstan-ignore method.notFound
        $session->setLastRealOrderId($incrementId); // @phpstan-ignore method.notFound
        $session->setLastOrderStatus($orderStatus); // @phpstan-ignore method.notFound
    }

    /**
     * Set the success-page fields from an order.
     *
     * @param int $quoteId
     * @param OrderInterface $order
     * @return void
     */
    public function armForOrder(int $quoteId, OrderInterface $order): void
    {
        $this->arm(
            $quoteId,
            (int) $order->getEntityId(),
            (string) $order->getIncrementId(),
            $order->getStatus() === null ? null : (string) $order->getStatus()
        );
    }

    /**
     * Set the success-page fields for a placed confirmation outcome.
     *
     * @param ConfirmationOutcome $outcome
     * @return bool false (nothing set) when the outcome is not a placed order
     */
    public function armForOutcome(ConfirmationOutcome $outcome): bool
    {
        if (!$outcome->isPlaced()
            || $outcome->orderId === null
            || $outcome->incrementId === null
            || $outcome->quoteId === null
        ) {
            return false;
        }

        $this->arm($outcome->quoteId, $outcome->orderId, $outcome->incrementId, $this->orderStatus($outcome->orderId));

        return true;
    }

    /**
     * Current status of an order; null when it cannot be read (the success page does not need it).
     *
     * @param int $orderId
     * @return string|null
     */
    private function orderStatus(int $orderId): ?string
    {
        try {
            $status = $this->orderRepository->get($orderId)->getStatus();
        } catch (NoSuchEntityException $exception) {
            $this->logger->warning('Affirm checkout: the placed order could not be loaded for the success page.');

            return null;
        }

        return $status === null ? null : (string) $status;
    }
}
