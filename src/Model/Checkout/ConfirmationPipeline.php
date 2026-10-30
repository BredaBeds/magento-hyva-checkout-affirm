<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Model\Checkout;

use Hyva\CheckoutAffirm\Model\Attempt\Attempt;
use Hyva\CheckoutAffirm\Model\Attempt\AttemptRepositoryInterface;
use Hyva\CheckoutAffirm\Model\Attempt\Ownership;
use Hyva\CheckoutAffirm\Model\Attempt\SnapshotInterface;
use Hyva\CheckoutAffirm\Model\Attempt\StaleAttemptPolicy;
use Hyva\CheckoutAffirm\Model\Attempt\State;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\CartLockedException;
use Magento\Quote\Model\Quote;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use UnexpectedValueException;

/**
 * Turns a checkout token Affirm returned for an attempt into an order, at most once.
 *
 * Order of work: resolve the attempt and its owner; re-check the cart (snapshot) and the payment method's
 * availability; verify the checkout at Affirm (nothing is written before it except failure states); confirm the
 * attempt with the token hash; claim it (confirmed -> placing); only the claim winner writes the token onto the
 * quote payment and places the order through Magento's standard placement. Every state change is a
 * compare-and-swap, so concurrent returns, refreshes and duplicate callbacks place one order.
 *
 * Messages are shopper-safe; logs carry reason codes and the order reference, never the token or attempt id.
 */
class ConfirmationPipeline implements ConfirmationPipelineInterface
{
    /**
     * Quote payment additional_information keys (copied to the order payment by Magento). The first two are the
     * ones Affirm's gateway commands read.
     */
    public const INFO_CHECKOUT_TOKEN = 'checkout_token';
    public const INFO_COUNTRY_CODE = 'country_code';
    public const INFO_ATTEMPT_ID = 'hyva_affirm_attempt_id';

    /**
     * Same event, payload and single dispatch as Affirm's own confirm controller.
     */
    public const EVENT_PLACE_ORDER_SUCCESS = 'affirm_place_order_success';

    /**
     * @param AttemptRepositoryInterface $attempts
     * @param Ownership $ownership
     * @param CartRepositoryInterface $cartRepository
     * @param SnapshotInterface $snapshot
     * @param VerifierInterface $verifier
     * @param OrderPlacement $orderPlacement
     * @param SuccessSession $successSession
     * @param AttemptRecovery $recovery
     * @param EventManagerInterface $eventManager
     * @param StoreManagerInterface $storeManager
     * @param DateTime $dateTime
     * @param StaleAttemptPolicy $stalePolicy
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly AttemptRepositoryInterface $attempts,
        private readonly Ownership $ownership,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly SnapshotInterface $snapshot,
        private readonly VerifierInterface $verifier,
        private readonly OrderPlacement $orderPlacement,
        private readonly SuccessSession $successSession,
        private readonly AttemptRecovery $recovery,
        private readonly EventManagerInterface $eventManager,
        private readonly StoreManagerInterface $storeManager,
        private readonly DateTime $dateTime,
        private readonly StaleAttemptPolicy $stalePolicy,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritdoc
     */
    public function confirm(
        #[\SensitiveParameter] string $attemptId,
        #[\SensitiveParameter] string $checkoutToken,
        ?Quote $quote = null
    ): ConfirmationOutcome {
        try {
            $attempt = $this->attempts->get($attemptId);
            if ($attempt === null) {
                return $this->failure(ConfirmationOutcome::REASON_NOT_FOUND);
            }
            // Ownership first: nobody else may replay, expire or fail someone's attempt.
            if (!$this->ownership->isOwner($attempt)) {
                return $this->failure(ConfirmationOutcome::REASON_NOT_OWNER);
            }

            return $this->resolve($attempt) ?? $this->confirmOpen($attempt, $checkoutToken, $quote);
        } catch (\Throwable $exception) {
            // Before the claim nothing irreversible happened: the attempt is unchanged and can be confirmed again.
            $this->logger->error('Affirm checkout: the confirmation could not be completed.', [
                'exception_class' => get_class($exception),
                'message' => $this->scrub($exception->getMessage(), $checkoutToken, $attemptId),
            ]);

            return ConfirmationOutcome::failed(
                ConfirmationOutcome::REASON_RETRY,
                (string) __('We could not confirm your Affirm payment. Please try again.')
            );
        }
    }

    /**
     * Steps for an initiated or confirmed attempt.
     *
     * @param Attempt $attempt
     * @param string $checkoutToken
     * @param Quote|null $quote
     * @return ConfirmationOutcome
     */
    private function confirmOpen(
        Attempt $attempt,
        #[\SensitiveParameter] string $checkoutToken,
        ?Quote $quote
    ): ConfirmationOutcome {
        if ($attempt->isExpired((int) $this->dateTime->gmtTimestamp())) {
            return $this->fail($attempt, Attempt::REASON_EXPIRED);
        }

        $quote = $this->loadQuote($attempt, $quote);
        if ($quote === null || !$this->snapshotMatches($attempt, $quote)) {
            return $this->fail($attempt, Attempt::REASON_CART_CHANGED);
        }
        if (!$this->isAvailable($quote)) {
            return $this->fail($attempt, Attempt::REASON_UNAVAILABLE);
        }

        $verification = $this->verifier->verify($attempt, $checkoutToken);
        if (!$verification->ok) {
            if ($verification->reason === VerificationResult::REASON_INVALID_TOKEN) {
                // No request was made, so nothing is known about the attempt: leave it open for the real return.
                return $this->failure(Attempt::REASON_VERIFICATION_FAILED, $attempt->quoteId);
            }
            if ($verification->isTemporary()) {
                // Affirm could not answer; the checkout it approved stays valid. Leave the attempt open: the
                // redirect return retries with its token, until the attempt expires.
                $this->logger->warning('Affirm checkout: Affirm could not be reached to confirm the checkout.', [
                    'reason' => $verification->reason,
                    'order_reference' => $attempt->reservedIncrementId,
                ]);

                return ConfirmationOutcome::inProgress(
                    $this->message(ConfirmationOutcome::REASON_RETRY),
                    $attempt->quoteId,
                    ConfirmationOutcome::REASON_RETRY
                );
            }
            $this->logger->warning('Affirm checkout: Affirm did not confirm the checkout.', [
                'reason' => $verification->reason,
                'order_reference' => $attempt->reservedIncrementId,
            ]);

            return $this->fail($attempt, Attempt::REASON_VERIFICATION_FAILED);
        }

        $tokenHash = Attempt::hashToken($checkoutToken);
        if (!$this->attempts->transition(
            $attempt->attemptId,
            [State::INITIATED],
            State::CONFIRMED,
            ['token_hash' => $tokenHash]
        )) {
            // Already confirmed: fine when it was confirmed with this token (a retry), otherwise another request
            // owns the attempt.
            $current = $this->attempts->get($attempt->attemptId);
            if ($current === null || $current->state !== State::CONFIRMED || !$current->hasToken($checkoutToken)) {
                return $this->afterLostRace($current);
            }
        }

        if ($this->attempts->hasUnresolvedForQuote($attempt->quoteId, $attempt->attemptId)) {
            // Another attempt for this cart may hold an authorization (the submit guard would refuse the order).
            // This one stays confirmed: a redirect return can be retried with its token once the other is resolved,
            // but the modal flow drops its stored token, so placing the order again starts a new Affirm checkout.
            return ConfirmationOutcome::failed(
                ConfirmationOutcome::REASON_IN_PROGRESS,
                (string) __('Payment for this cart is still being confirmed. Please wait a moment or contact us.'),
                $attempt->quoteId
            );
        }

        if (!$this->attempts->transition(
            $attempt->attemptId,
            [State::CONFIRMED],
            State::PLACING,
            [],
            ['token_hash' => $tokenHash]
        )) {
            return $this->afterLostRace($this->attempts->get($attempt->attemptId));
        }

        return $this->place($attempt, $quote, $checkoutToken);
    }

    /**
     * Write the token onto the quote payment and place the order (claim winner only).
     *
     * @param Attempt $attempt
     * @param Quote $quote
     * @param string $checkoutToken
     * @return ConfirmationOutcome
     */
    private function place(
        Attempt $attempt,
        Quote $quote,
        #[\SensitiveParameter] string $checkoutToken
    ): ConfirmationOutcome {
        try {
            $this->writePaymentInformation($quote, $attempt, $checkoutToken);
            $orderId = $this->orderPlacement->place($quote, $attempt->attemptId, $attempt->reservedIncrementId);
        } catch (CartLockedException) {
            // Magento's cart mutex refused before anything reached Affirm: hand the attempt back for a retry.
            if (!$this->attempts->transition($attempt->attemptId, [State::PLACING], State::CONFIRMED)) {
                return $this->afterLostRace($this->attempts->get($attempt->attemptId));
            }

            return ConfirmationOutcome::inProgress(
                $this->message(ConfirmationOutcome::REASON_RETRY),
                $attempt->quoteId,
                ConfirmationOutcome::REASON_RETRY
            );
        } catch (\Throwable $exception) {
            return $this->recover($attempt, $quote, $exception, $checkoutToken);
        }

        if ($orderId <= 0) {
            return $this->recover(
                $attempt,
                $quote,
                new UnexpectedValueException('The order placement returned no order id.'),
                $checkoutToken
            );
        }

        return $this->complete($attempt, $quote, $orderId, null, false);
    }

    /**
     * Decide what an exception from the placement means.
     *
     * An order of this attempt that exists anyway is completed (an order of another placement with the reserved
     * number counts as no order); an exception after the authorize request leaves the attempt uncertain; an
     * exception before it fails the attempt.
     *
     * @param Attempt $attempt
     * @param Quote $quote
     * @param \Throwable $exception
     * @param string $checkoutToken
     * @return ConfirmationOutcome
     */
    private function recover(
        Attempt $attempt,
        Quote $quote,
        \Throwable $exception,
        #[\SensitiveParameter] string $checkoutToken
    ): ConfirmationOutcome {
        $this->logger->error('Affirm checkout: the order placement failed.', [
            'exception_class' => get_class($exception),
            'message' => $this->scrub($exception->getMessage(), $checkoutToken, $attempt->attemptId),
            'order_reference' => $attempt->reservedIncrementId,
        ]);

        try {
            $order = $this->recovery->findOrder($attempt);
            if ($order !== null) {
                return $this->complete($attempt, $quote, (int) $order->getEntityId(), $order, true);
            }

            $current = $this->attempts->get($attempt->attemptId);
            if ($current?->state === State::AUTHORIZING) {
                return $this->markUncertain($attempt);
            }
            if ($current?->state === State::PLACING) {
                // The authorize request was never sent: no payment exists and the shopper may start again.
                if ($this->attempts->transition(
                    $attempt->attemptId,
                    [State::PLACING],
                    State::FAILED,
                    ['failure_reason' => Attempt::REASON_PLACEMENT_FAILED]
                )) {
                    $this->clearPaymentInformation($quote, $attempt);

                    return $this->failure(Attempt::REASON_PLACEMENT_FAILED, $attempt->quoteId);
                }
                $current = $this->attempts->get($attempt->attemptId);
            }

            return $this->afterLostRace($current);
        } catch (\Throwable $recoveryException) {
            // Nothing proves that no authorization exists: never tell the shopper that no payment was taken.
            $this->logger->error('Affirm checkout: the outcome of a failed order placement is unknown.', [
                'exception_class' => get_class($recoveryException),
                'order_reference' => $attempt->reservedIncrementId,
            ]);

            return ConfirmationOutcome::uncertain(
                $this->uncertainMessage($attempt->reservedIncrementId),
                $attempt->quoteId,
                $attempt->reservedIncrementId
            );
        }
    }

    /**
     * Record a placed order (placed now, or found after an interrupted placement).
     *
     * Once the order exists the outcome is "placed", whatever happens to the bookkeeping.
     *
     * @param Attempt $attempt
     * @param Quote $quote
     * @param int $orderId
     * @param OrderInterface|null $order
     * @param bool $interrupted the placement threw although the order was saved
     * @return ConfirmationOutcome
     */
    private function complete(
        Attempt $attempt,
        Quote $quote,
        int $orderId,
        ?OrderInterface $order,
        bool $interrupted
    ): ConfirmationOutcome {
        $incrementId = $attempt->reservedIncrementId;
        try {
            $order ??= $this->orderPlacement->getOrder($orderId);
        } catch (\Throwable $exception) {
            $this->logBookkeepingFailure('the placed order could not be loaded', $exception, $incrementId);
        }
        if ($order !== null && (string) $order->getIncrementId() !== '') {
            $incrementId = (string) $order->getIncrementId();
        }

        $placed = false;
        try {
            $placed = $this->attempts->transition(
                $attempt->attemptId,
                [State::PLACING, State::AUTHORIZING],
                State::PLACED,
                ['order_increment_id' => $incrementId, 'order_id' => $orderId]
            );
            if (!$placed) {
                $this->logger->warning(
                    'Affirm checkout: the order was placed but its checkout attempt had changed state.',
                    ['order_reference' => $incrementId]
                );
            }
        } catch (\Throwable $exception) {
            $this->logBookkeepingFailure('the attempt could not be marked placed', $exception, $incrementId);
        }

        if ($interrupted) {
            try {
                $this->finishInterrupted($quote, $orderId, $incrementId, $order);
            } catch (\Throwable $exception) {
                $this->logBookkeepingFailure(
                    'the interrupted placement could not be finished',
                    $exception,
                    $incrementId
                );
            }
        }

        if ($placed && $order !== null) {
            $this->dispatchPlaced($order, $quote);
        }

        return ConfirmationOutcome::placed($orderId, $incrementId, $attempt->quoteId);
    }

    /**
     * Repair what Magento's placement did not finish when it threw after saving the order (for example a failing
     * sales_model_service_quote_submit_success observer): the quote stays active and the success-page session
     * fields are missing. checkout_submit_all_after is deliberately not re-dispatched.
     *
     * @param Quote $quote
     * @param int $orderId
     * @param string $incrementId
     * @param OrderInterface|null $order
     * @return void
     */
    private function finishInterrupted(Quote $quote, int $orderId, string $incrementId, ?OrderInterface $order): void
    {
        $this->orderPlacement->deactivateQuote($quote);
        if ($order !== null) {
            $this->successSession->armForOrder((int) $quote->getId(), $order);
        } else {
            $this->successSession->arm((int) $quote->getId(), $orderId, $incrementId);
        }
        $this->logger->warning(
            'Affirm checkout: the order exists but its placement did not finish; '
            . 'checkout_submit_all_after observers did not run for it.',
            ['order_reference' => $incrementId]
        );
    }

    /**
     * Move an authorizing attempt to uncertain and tell the merchant.
     *
     * @param Attempt $attempt
     * @return ConfirmationOutcome
     */
    private function markUncertain(Attempt $attempt): ConfirmationOutcome
    {
        if (!$this->recovery->markUncertain($attempt, [State::AUTHORIZING])) {
            return $this->afterLostRace($this->attempts->get($attempt->attemptId));
        }

        return ConfirmationOutcome::uncertain(
            $this->uncertainMessage($attempt->reservedIncrementId),
            $attempt->quoteId,
            $attempt->reservedIncrementId
        );
    }

    /**
     * Log a failed bookkeeping step after the order exists.
     *
     * @param string $what
     * @param \Throwable $exception
     * @param string $orderReference
     * @return void
     */
    private function logBookkeepingFailure(string $what, \Throwable $exception, string $orderReference): void
    {
        $this->logger->error('Affirm checkout: ' . $what . '.', [
            'exception_class' => get_class($exception),
            'order_reference' => $orderReference,
        ]);
    }

    /**
     * Outcome for an attempt that is not open, or null when it is (initiated or confirmed).
     *
     * A placement that has not changed for the stale threshold is no longer reported as in progress: its process
     * died, so the shopper gets the order reference instead of a page that refreshes forever. The escalation cron
     * job settles the attempt (placed with its order, or uncertain with a merchant notification).
     *
     * @param Attempt $attempt
     * @return ConfirmationOutcome|null
     */
    private function resolve(Attempt $attempt): ?ConfirmationOutcome
    {
        switch ($attempt->state) {
            case State::PLACED:
                if ((int) $attempt->orderId > 0 && (string) $attempt->orderIncrementId !== '') {
                    return ConfirmationOutcome::placed(
                        (int) $attempt->orderId,
                        (string) $attempt->orderIncrementId,
                        $attempt->quoteId,
                        true
                    );
                }

                return ConfirmationOutcome::uncertain(
                    $this->uncertainMessage($attempt->reservedIncrementId),
                    $attempt->quoteId,
                    $attempt->reservedIncrementId
                );
            case State::PLACING:
            case State::AUTHORIZING:
                if ($this->stalePolicy->isStale($attempt, (int) $this->dateTime->gmtTimestamp())) {
                    return ConfirmationOutcome::uncertain(
                        $this->uncertainMessage($attempt->reservedIncrementId),
                        $attempt->quoteId,
                        $attempt->reservedIncrementId
                    );
                }

                return ConfirmationOutcome::inProgress(
                    $this->message(ConfirmationOutcome::REASON_IN_PROGRESS),
                    $attempt->quoteId
                );
            case State::UNCERTAIN:
                return ConfirmationOutcome::uncertain(
                    $this->uncertainMessage($attempt->reservedIncrementId),
                    $attempt->quoteId,
                    $attempt->reservedIncrementId
                );
            case State::FAILED:
            case State::RESOLVED:
                return $this->failure(
                    (string) ($attempt->failureReason ?: Attempt::REASON_PLACEMENT_FAILED),
                    $attempt->quoteId
                );
            default:
                return null;
        }
    }

    /**
     * Outcome after a compare-and-swap was lost to another request.
     *
     * @param Attempt|null $current the attempt as stored now
     * @return ConfirmationOutcome
     */
    private function afterLostRace(?Attempt $current): ConfirmationOutcome
    {
        if ($current === null) {
            return $this->failure(ConfirmationOutcome::REASON_NOT_FOUND);
        }

        return $this->resolve($current) ?? ConfirmationOutcome::inProgress(
            $this->message(ConfirmationOutcome::REASON_IN_PROGRESS),
            $current->quoteId
        );
    }

    /**
     * Fail an open attempt (compare-and-swap from initiated or confirmed).
     *
     * @param Attempt $attempt
     * @param string $reason an Attempt::REASON_* code
     * @return ConfirmationOutcome
     */
    private function fail(Attempt $attempt, string $reason): ConfirmationOutcome
    {
        $failed = $this->attempts->transition(
            $attempt->attemptId,
            State::open(),
            State::FAILED,
            ['failure_reason' => $reason]
        );
        if ($failed) {
            return $this->failure($reason, $attempt->quoteId);
        }

        return $this->afterLostRace($this->attempts->get($attempt->attemptId));
    }

    /**
     * The quote to place, or null when it is no longer the cart the attempt was started for.
     *
     * Modal mode passes the request's instance; the redirect return loads the attempt's quote.
     *
     * @param Attempt $attempt
     * @param Quote|null $quote
     * @return Quote|null
     */
    private function loadQuote(Attempt $attempt, ?Quote $quote): ?Quote
    {
        if ($quote === null) {
            try {
                $loaded = $this->cartRepository->get($attempt->quoteId);
            } catch (NoSuchEntityException) {
                return null;
            }
            if (!$loaded instanceof Quote) {
                return null;
            }
            $quote = $loaded;
        }

        // Affirm's authorization uses the current store's keys and currency checks.
        $currentStoreId = (int) $this->storeManager->getStore()->getId();
        if ((int) $quote->getId() !== $attempt->quoteId
            || !$quote->getIsActive()
            || (int) $quote->getStoreId() !== $attempt->storeId
            || $currentStoreId !== $attempt->storeId
            || $quote->getPayment()->getMethod() !== $attempt->paymentMethod
        ) {
            return null;
        }

        return $quote;
    }

    /**
     * Recollect the totals and compare the cart with the one sent to Affirm.
     *
     * @param Attempt $attempt
     * @param Quote $quote
     * @return bool
     */
    private function snapshotMatches(Attempt $attempt, Quote $quote): bool
    {
        $quote->setTotalsCollectedFlag(false); // @phpstan-ignore method.notFound (magic DataObject setter)
        $quote->collectTotals();

        return hash_equals($attempt->snapshotHash, $this->snapshot->hash($quote));
    }

    /**
     * Explicit availability check (dispatches payment_method_is_active, so merchant observers can still refuse).
     *
     * @param Quote $quote
     * @return bool
     */
    private function isAvailable(Quote $quote): bool
    {
        try {
            $method = $quote->getPayment()->getMethodInstance();
        } catch (LocalizedException) {
            return false;
        }

        return (bool) $method->isAvailable($quote);
    }

    /**
     * Store what Affirm's gateway commands need (token, country) and the attempt id on the quote payment.
     *
     * Saved through the payment model, not the cart repository: the repository would drop its cached quote and
     * the placement would then run on another instance than the session's.
     *
     * @param Quote $quote
     * @param Attempt $attempt
     * @param string $checkoutToken
     * @return void
     */
    private function writePaymentInformation(
        Quote $quote,
        Attempt $attempt,
        #[\SensitiveParameter] string $checkoutToken
    ): void {
        $payment = $quote->getPayment();
        $payment->setAdditionalInformation(self::INFO_CHECKOUT_TOKEN, $checkoutToken);
        $payment->setAdditionalInformation(self::INFO_COUNTRY_CODE, $attempt->currency === 'CAD' ? 'CAN' : 'USA');
        $payment->setAdditionalInformation(self::INFO_ATTEMPT_ID, $attempt->attemptId);
        $payment->save();
    }

    /**
     * Remove this attempt's token from the quote payment after a definitive failure.
     *
     * @param Quote $quote
     * @param Attempt $attempt
     * @return void
     */
    private function clearPaymentInformation(Quote $quote, Attempt $attempt): void
    {
        try {
            $payment = $quote->getPayment();
            if ($payment->getAdditionalInformation(self::INFO_ATTEMPT_ID) !== $attempt->attemptId) {
                return;
            }
            $payment->unsAdditionalInformation(self::INFO_CHECKOUT_TOKEN);
            $payment->unsAdditionalInformation(self::INFO_COUNTRY_CODE);
            $payment->unsAdditionalInformation(self::INFO_ATTEMPT_ID);
            $payment->save();
        } catch (\Throwable $exception) {
            $this->logger->warning('Affirm checkout: the checkout token could not be removed from the cart.', [
                'exception_class' => get_class($exception),
                'order_reference' => $attempt->reservedIncrementId,
            ]);
        }
    }

    /**
     * Dispatch affirm_place_order_success once per placed attempt; observer failures do not change the outcome.
     *
     * @param OrderInterface $order
     * @param Quote $quote
     * @return void
     */
    private function dispatchPlaced(OrderInterface $order, Quote $quote): void
    {
        try {
            $this->eventManager->dispatch(self::EVENT_PLACE_ORDER_SUCCESS, ['order' => $order, 'quote' => $quote]);
        } catch (\Throwable $exception) {
            $this->logger->error('Affirm checkout: an affirm_place_order_success observer failed.', [
                'exception_class' => get_class($exception),
                'order_reference' => (string) $order->getIncrementId(),
            ]);
        }
    }

    /**
     * A failed outcome with the shopper message for its reason.
     *
     * @param string $reason
     * @param int|null $quoteId
     * @return ConfirmationOutcome
     */
    private function failure(string $reason, ?int $quoteId = null): ConfirmationOutcome
    {
        return ConfirmationOutcome::failed($reason, $this->message($reason), $quoteId);
    }

    /**
     * Shopper message for a reason code.
     *
     * @param string $reason
     * @return string
     */
    private function message(string $reason): string
    {
        switch ($reason) {
            case ConfirmationOutcome::REASON_IN_PROGRESS:
            case ConfirmationOutcome::REASON_RETRY:
                return (string) __('Your Affirm payment is being confirmed. Please wait a moment.');
            case ConfirmationOutcome::REASON_NOT_FOUND:
                return (string) __('We could not find this Affirm payment. Please start the payment again.');
            case ConfirmationOutcome::REASON_NOT_OWNER:
                return (string) __('This Affirm payment belongs to another session. Please start the payment again.');
            case Attempt::REASON_EXPIRED:
                return (string) __('This Affirm payment has expired. Please start the payment again.');
            case Attempt::REASON_CANCELLED:
                return (string) __('The Affirm payment was cancelled. Please choose a payment method to continue.');
            case Attempt::REASON_DECLINED:
                return (string) __('Affirm could not approve this payment. Please choose another payment method.');
            case Attempt::REASON_VERIFICATION_FAILED:
                return (string) __('We could not verify this payment with Affirm. Please start the payment again.');
            case Attempt::REASON_CART_CHANGED:
                return (string) __('Your cart changed during the Affirm payment. Please start the payment again.');
            case Attempt::REASON_UNAVAILABLE:
                return (string) __('Affirm is not available for this order. Please choose another payment method.');
            case Attempt::REASON_SUPERSEDED:
                return (string) __('This Affirm payment was replaced by a newer one. Please start the payment again.');
            case Attempt::REASON_RESOLVED_PLACED:
                return (string) __('This Affirm payment is already complete. Please check your e-mail for the order.');
            case Attempt::REASON_RESOLVED_VOIDED:
                return (string) __('This Affirm payment was cancelled. Please start the payment again.');
            default:
                return (string) __('We could not place your order and no payment was taken. Please try again.');
        }
    }

    /**
     * Shopper message for an attempt that awaits reconciliation.
     *
     * @param string $orderReference
     * @return string
     */
    private function uncertainMessage(string $orderReference): string
    {
        return (string) __(
            'We are confirming your Affirm payment for order #%1. Please do not pay again; '
            . 'if you do not receive an order confirmation, contact us with this order number.',
            $orderReference
        );
    }

    /**
     * Remove the token and the attempt id from a text before it is logged.
     *
     * @param string $text
     * @param string $checkoutToken
     * @param string $attemptId
     * @return string
     */
    private function scrub(
        string $text,
        #[\SensitiveParameter] string $checkoutToken,
        #[\SensitiveParameter] string $attemptId
    ): string {
        $secrets = array_values(array_filter([$checkoutToken, $attemptId], static fn (string $s): bool => $s !== ''));

        return $secrets === [] ? $text : str_replace($secrets, '[redacted]', $text);
    }
}
