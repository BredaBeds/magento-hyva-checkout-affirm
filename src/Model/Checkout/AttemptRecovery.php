<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Model\Checkout;

use Hyva\CheckoutAffirm\Model\Attempt\AdminNotifier;
use Hyva\CheckoutAffirm\Model\Attempt\Attempt;
use Hyva\CheckoutAffirm\Model\Attempt\AttemptRepositoryInterface;
use Hyva\CheckoutAffirm\Model\Attempt\StaleAttemptPolicy;
use Hyva\CheckoutAffirm\Model\Attempt\State;
use Magento\Sales\Api\Data\OrderInterface;
use Psr\Log\LoggerInterface;

/**
 * What happens to an attempt whose placement did not finish: shared by the confirmation pipeline (the placement
 * threw) and the escalation cron job (the placing process died).
 *
 * An order is linked to the attempt only when it provably belongs to it. Otherwise the attempt is either failed by
 * the pipeline (no authorization was sent) or moved to uncertain with a CRITICAL log line and an admin inbox
 * message. Log lines name the order reference only.
 */
class AttemptRecovery
{
    public const ESCALATED_PLACED = 'placed';
    public const ESCALATED_UNCERTAIN = 'uncertain';
    public const ESCALATION_SKIPPED = 'skipped';

    /**
     * @param AttemptRepositoryInterface $attempts
     * @param OrderPlacement $orderPlacement
     * @param AdminNotifier $adminNotifier
     * @param StaleAttemptPolicy $stalePolicy
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly AttemptRepositoryInterface $attempts,
        private readonly OrderPlacement $orderPlacement,
        private readonly AdminNotifier $adminNotifier,
        private readonly StaleAttemptPolicy $stalePolicy,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * The order placed for the attempt, looked up by its reserved order number and store.
     *
     * Magento reserves a new number when the reserved one was used meanwhile, and every attempt of a cart shares
     * the cart's number, so an order with that number may come from another placement (another payment method, a
     * sibling attempt). It is accepted only when it comes from the attempt's cart, was paid with the attempt's
     * method and carries the attempt id on its payment.
     *
     * @param Attempt $attempt
     * @return OrderInterface|null
     */
    public function findOrder(Attempt $attempt): ?OrderInterface
    {
        $order = $this->orderPlacement->findOrder($attempt->reservedIncrementId, $attempt->storeId);
        if ($order === null || (int) $order->getEntityId() <= 0) {
            return null;
        }
        if (!$this->belongsTo($order, $attempt)) {
            $this->logger->warning(
                'Affirm checkout: an order with the reserved order number was not placed for this checkout attempt;'
                . ' it is not linked to the attempt.',
                ['order_reference' => (string) $order->getIncrementId()]
            );

            return null;
        }

        return $order;
    }

    /**
     * Whether the order was placed from the attempt's cart, with its payment method and for this attempt.
     *
     * @param OrderInterface $order
     * @param Attempt $attempt
     * @return bool
     */
    public function belongsTo(OrderInterface $order, Attempt $attempt): bool
    {
        $payment = $order->getPayment();
        if ($payment === null
            || (int) $order->getQuoteId() !== $attempt->quoteId
            || (string) $payment->getMethod() !== $attempt->paymentMethod
        ) {
            return false;
        }
        $information = $payment->getAdditionalInformation();
        $attemptId = is_array($information) ? ($information[ConfirmationPipeline::INFO_ATTEMPT_ID] ?? null) : null;

        return is_string($attemptId) && hash_equals($attempt->attemptId, $attemptId);
    }

    /**
     * Move the attempt to uncertain (compare-and-swap), log a CRITICAL line and notify the merchant.
     *
     * @param Attempt $attempt
     * @param list<State> $fromStates
     * @param array<string, mixed> $conditions further compare-and-swap conditions
     * @return bool whether this call moved the attempt
     */
    public function markUncertain(Attempt $attempt, array $fromStates, array $conditions = []): bool
    {
        if (!$this->attempts->transition($attempt->attemptId, $fromStates, State::UNCERTAIN, [], $conditions)) {
            return false;
        }
        $this->logger->critical(
            'Affirm checkout: Affirm may hold an authorization without an order; the attempt needs review.',
            ['order_reference' => $attempt->reservedIncrementId]
        );
        $this->adminNotifier->notifyUncertain($attempt->reservedIncrementId);

        return true;
    }

    /**
     * Settle an attempt that its placement process left in placing or authorizing (escalation cron job).
     *
     * Only an attempt that is stale at $now and still exactly as read (same state, same updated_at) is changed, so
     * a placement that is still alive, and a second run of the job, leave it alone. With its order, the attempt
     * becomes placed and the cart is deactivated; without, it becomes uncertain, also from placing: the tracker's
     * authorizing write may have been rolled back with a surrounding transaction (it logs that as CRITICAL), so a
     * placing row does not prove that the authorize request was never sent.
     *
     * @param Attempt $attempt the attempt as read
     * @param int $now Unix timestamp
     * @return string one of the ESCALATED_* and ESCALATION_SKIPPED constants
     */
    public function escalate(Attempt $attempt, int $now): string
    {
        if (!$this->stalePolicy->isStale($attempt, $now)) {
            return self::ESCALATION_SKIPPED;
        }
        $asRead = ['updated_at' => $attempt->updatedAt];

        $order = $this->findOrder($attempt);
        if ($order !== null) {
            return $this->linkOrder($attempt, $order, $asRead) ? self::ESCALATED_PLACED : self::ESCALATION_SKIPPED;
        }

        return $this->markUncertain($attempt, [$attempt->state], $asRead)
            ? self::ESCALATED_UNCERTAIN
            : self::ESCALATION_SKIPPED;
    }

    /**
     * Record the order of an abandoned placement and deactivate its cart.
     *
     * The success page cannot be prepared (no shopper request) and affirm_place_order_success is not dispatched:
     * its observers expect the storefront request Affirm's own controller dispatches it in.
     *
     * @param Attempt $attempt
     * @param OrderInterface $order
     * @param array<string, mixed> $conditions
     * @return bool whether this call moved the attempt
     */
    private function linkOrder(Attempt $attempt, OrderInterface $order, array $conditions): bool
    {
        $incrementId = (string) $order->getIncrementId();
        if ($incrementId === '') {
            $incrementId = $attempt->reservedIncrementId;
        }
        if (!$this->attempts->transition(
            $attempt->attemptId,
            [$attempt->state],
            State::PLACED,
            ['order_increment_id' => $incrementId, 'order_id' => (int) $order->getEntityId()],
            $conditions
        )) {
            return false;
        }

        try {
            $this->orderPlacement->deactivateQuoteById($attempt->quoteId);
        } catch (\Throwable $exception) {
            $this->logger->error('Affirm checkout: the cart of a recovered order could not be deactivated.', [
                'exception_class' => get_class($exception),
                'order_reference' => $incrementId,
            ]);
        }
        $this->logger->warning(
            'Affirm checkout: an abandoned placement was linked to its order; checkout_submit_all_after and'
            . ' affirm_place_order_success did not run for it.',
            ['order_reference' => $incrementId]
        );

        return true;
    }
}
