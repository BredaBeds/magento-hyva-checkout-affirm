<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Controller\Checkout;

use Hyva\CheckoutAffirm\Model\Attempt\Attempt;
use Hyva\CheckoutAffirm\Model\Attempt\AttemptRepositoryInterface;
use Hyva\CheckoutAffirm\Model\Attempt\Ownership;
use Hyva\CheckoutAffirm\Model\Attempt\State;
use Hyva\CheckoutAffirm\Model\Checkout\PaymentStep;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Message\ManagerInterface as MessageManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Cancel and decline URLs of the redirect flow: hyva_affirm/checkout/cancel/attempt/<id>[/reason/declined].
 *
 * Fails the attempt (initiated or confirmed only) for its owner and returns to the payment step. No order exists
 * before Affirm confirms, so the cart is untouched.
 */
class Cancel implements HttpGetActionInterface
{
    public const ROUTE = 'hyva_affirm/checkout/cancel';
    public const REASON_DECLINED = 'declined';

    /**
     * @param RequestInterface $request
     * @param RedirectFactory $redirectFactory
     * @param MessageManagerInterface $messageManager
     * @param AttemptRepositoryInterface $attempts
     * @param Ownership $ownership
     * @param PaymentStep $paymentStep
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly RedirectFactory $redirectFactory,
        private readonly MessageManagerInterface $messageManager,
        private readonly AttemptRepositoryInterface $attempts,
        private readonly Ownership $ownership,
        private readonly PaymentStep $paymentStep,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Cancel the attempt and go back to the payment step.
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $declined = $this->request->getParam('reason') === self::REASON_DECLINED;
        $attemptId = $this->request->getParam('attempt');
        $state = null;

        try {
            $attempt = is_string($attemptId) ? $this->attempts->get($attemptId) : null;
            if ($attempt !== null && $this->ownership->isOwner($attempt)) {
                $state = $this->cancel($attempt, $declined);
            }
        } catch (\Throwable $exception) {
            $this->logger->error(
                'Affirm checkout: the cancelled Affirm payment could not be recorded.',
                ['exception_class' => get_class($exception)]
            );
        }

        if ($state !== null && $state->isUnresolved()) {
            $this->messageManager->addNoticeMessage(
                __('Payment for this cart is still being confirmed. Please wait a moment or contact us.')
            );
        } elseif ($state === State::PLACED) {
            $this->messageManager->addNoticeMessage(
                __('This Affirm payment is already complete. Please check your e-mail for the order.')
            );
        } elseif ($declined) {
            $this->messageManager->addWarningMessage(
                __('Affirm could not approve this payment. Please choose another payment method.')
            );
        } else {
            $this->messageManager->addNoticeMessage(
                __('The Affirm payment was cancelled. Please choose a payment method to continue.')
            );
        }

        $redirect = $this->paymentStep->applyTo($this->redirectFactory->create());
        $redirect->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0', true);
        $redirect->setHeader('Pragma', 'no-cache', true);

        return $redirect;
    }

    /**
     * Fail an open attempt; returns the state the attempt is in afterwards.
     *
     * @param Attempt $attempt
     * @param bool $declined
     * @return State|null null when the attempt no longer exists
     */
    private function cancel(Attempt $attempt, bool $declined): ?State
    {
        if (!$attempt->state->isOpen()) {
            return $attempt->state;
        }
        $reason = $declined ? Attempt::REASON_DECLINED : Attempt::REASON_CANCELLED;
        if ($this->attempts->transition(
            $attempt->attemptId,
            State::open(),
            State::FAILED,
            ['failure_reason' => $reason]
        )) {
            return State::FAILED;
        }

        return $this->attempts->get($attempt->attemptId)?->state;
    }
}
