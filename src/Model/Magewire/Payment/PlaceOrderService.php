<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Model\Magewire\Payment;

use Exception;
use Hyva\Checkout\Model\Magewire\Payment\AbstractOrderData;
use Hyva\Checkout\Model\Magewire\Payment\AbstractPlaceOrderService;
use Hyva\CheckoutAffirm\Model\Attempt\Attempt;
use Hyva\CheckoutAffirm\Model\Checkout\ConfirmationOutcome;
use Hyva\CheckoutAffirm\Model\Checkout\ConfirmationPipelineInterface;
use Hyva\CheckoutAffirm\Model\Checkout\SuccessSession;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Model\Quote;
use Magewirephp\Magewire\Component;
use Psr\Log\LoggerInterface;

/**
 * Hyvä place-order service for Affirm's modal flow: turns the checkout token Affirm returned into the order.
 *
 * The browser stores the token and the attempt id in Hyvä's "payment" storage group after Affirm's onSuccess;
 * the confirmation pipeline verifies them at Affirm and places the order through Magento's standard placement.
 * Every failure is a LocalizedException with a shopper-safe message: Hyvä shows and logs the message, so it
 * never contains the token or the attempt id, and no previous exception is chained.
 */
class PlaceOrderService extends AbstractPlaceOrderService
{
    /**
     * Keys of Hyvä's "payment" storage group written by the method's JavaScript.
     */
    public const KEY_CHECKOUT_TOKEN = 'checkout_token';
    public const KEY_ATTEMPT_ID = 'attempt_id';

    /**
     * Shape of an Affirm checkout token; anything else never reaches the pipeline.
     */
    private const TOKEN_PATTERN = '/^[A-Za-z0-9_-]{1,128}\z/';

    /**
     * @var int|null order placed (or found placed) by this request
     */
    private ?int $placedOrderId = null;

    /**
     * @param CartManagementInterface $cartManagement
     * @param ConfirmationPipelineInterface $confirmationPipeline
     * @param SuccessSession $successSession
     * @param LoggerInterface $logger
     * @param AbstractOrderData|null $orderData
     */
    public function __construct(
        CartManagementInterface $cartManagement,
        private readonly ConfirmationPipelineInterface $confirmationPipeline,
        private readonly SuccessSession $successSession,
        private readonly LoggerInterface $logger,
        ?AbstractOrderData $orderData = null
    ) {
        parent::__construct($cartManagement, $orderData);
    }

    /**
     * Confirm the Affirm checkout of the stored attempt and return the order id.
     *
     * @param Quote $quote the request's checkout quote
     * @return int
     * @throws LocalizedException when no order exists for the attempt (yet)
     */
    public function placeOrder(Quote $quote): int
    {
        $this->placedOrderId = null;
        [$attemptId, $checkoutToken] = $this->readPayment();

        try {
            $outcome = $this->confirmationPipeline->confirm($attemptId, $checkoutToken, $quote);
        } catch (\Throwable $e) {
            $this->logger->error('Hyva_CheckoutAffirm: the Affirm confirmation failed unexpectedly.', [
                'exception' => get_class($e),
                'location' => $e->getFile() . ':' . $e->getLine(),
            ]);

            throw new LocalizedException(
                __('We could not confirm your Affirm payment. Please wait a moment, then try again.')
            );
        }

        if (!$outcome->isPlaced() || $outcome->orderId === null) {
            throw new LocalizedException(__('%1', $this->messageOf($outcome)));
        }

        if ($outcome->replay) {
            // An earlier request placed the order, so Magento's placement did not prepare the success page.
            $this->armSuccessPage($outcome);
        }
        $this->placedOrderId = $outcome->orderId;

        return $outcome->orderId;
    }

    /**
     * Always attempt placement: missing or invalid data must surface as an error, not as a skipped placement
     * (Hyvä would still redirect to the success page).
     *
     * @return bool
     */
    public function canPlaceOrder(): bool
    {
        return true;
    }

    /**
     * Redirect to the success page only when an order exists.
     *
     * @return bool
     */
    public function canRedirect(): bool
    {
        return $this->placedOrderId !== null;
    }

    /**
     * Rethrow: Hyvä then shows the message and keeps the shopper on the payment step.
     *
     * @param Exception $exception
     * @param Component $component
     * @param Quote $quote
     * @return void
     * @throws Exception
     */
    public function handleException(Exception $exception, Component $component, Quote $quote): void
    {
        throw $exception;
    }

    /**
     * Attempt id and checkout token from Hyvä's "payment" storage group.
     *
     * @return array{0: string, 1: string}
     * @throws LocalizedException when either is missing or malformed
     */
    private function readPayment(): array
    {
        try {
            $payment = $this->getData()->getPayment();
        } catch (\TypeError) {
            $payment = [];
        }

        $attemptId = $payment[self::KEY_ATTEMPT_ID] ?? null;
        $checkoutToken = $payment[self::KEY_CHECKOUT_TOKEN] ?? null;
        if (!is_string($attemptId) || !Attempt::isValidId($attemptId)
            || !is_string($checkoutToken) || preg_match(self::TOKEN_PATTERN, $checkoutToken) !== 1
        ) {
            throw new LocalizedException(
                __('Your Affirm payment could not be confirmed. Please place the order again.')
            );
        }

        return [$attemptId, $checkoutToken];
    }

    /**
     * The outcome's shopper message, or a generic one.
     *
     * @param ConfirmationOutcome $outcome
     * @return string
     */
    private function messageOf(ConfirmationOutcome $outcome): string
    {
        if ($outcome->isInProgress() || $outcome->reason === ConfirmationOutcome::REASON_IN_PROGRESS) {
            // Another placement holds the cart (cart lock, or another attempt of the cart is unresolved). The modal
            // flow drops the stored token after a failed placement, so waiting alone completes nothing: placing the
            // order again starts a new Affirm checkout (or reports that the other payment still blocks the cart).
            return (string) __(
                'Your Affirm payment could not be completed while another payment for this cart is being processed.'
                . ' Please place the order again in a moment.'
            );
        }
        if ($outcome->message !== '') {
            return $outcome->message;
        }

        if ($outcome->isUncertain()) {
            return (string) __('Your Affirm payment is still being confirmed. Please wait a moment, then try again.');
        }

        return (string) __('Your Affirm payment was not completed. Please try again or use another payment method.');
    }

    /**
     * Prepare the success page for an order placed by an earlier request; a failure never hides the order.
     *
     * @param ConfirmationOutcome $outcome
     * @return void
     */
    private function armSuccessPage(ConfirmationOutcome $outcome): void
    {
        try {
            $this->successSession->armForOutcome($outcome);
        } catch (\Throwable $e) {
            $this->logger->error('Hyva_CheckoutAffirm: the success page could not be prepared for a placed order.', [
                'exception' => get_class($e),
                'order_id' => $outcome->orderId,
            ]);
        }
    }
}
