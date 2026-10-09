<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Plugin\HyvaCheckout;

use Hyva\Checkout\Magewire\Checkout\Payment\MethodList;
use Hyva\CheckoutAffirm\Magewire\Payment\Method\AffirmGateway;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\PaymentInterfaceFactory;
use Magento\Quote\Api\PaymentMethodManagementInterface;
use Magento\Quote\Model\Quote;
use Psr\Log\LoggerInterface;

/**
 * Keeps the promise of a cart "Checkout with Affirm" button: Affirm is selected when Hyvä Checkout's payment method
 * list is built, even when the checkout replaced it in between.
 *
 * Hyvä Checkout's "Enable first available payment method" setting
 * (hyva_themes_checkout/developer/experimental/enable_first_payment_method_available) sets the first available
 * method on the first checkout page load of a session, over the method the quote already has. A cart button that
 * selected Affirm and then opened the checkout loses its choice that way.
 *
 * Contract: a cart button stores the id of the quote it selected Affirm for under the checkout session key
 * SESSION_KEY (Hyva_AstoundAffirm's cart button does; this module does not depend on it). Before the method list
 * reads the quote's method in boot(), a record for the current quote selects Affirm through Magento's
 * PaymentMethodManagementInterface::set() (with Magento's availability checks) when Affirm is among the quote's
 * available methods, and the record is removed. While Affirm is not available the record stays and nothing is
 * selected. It is removed as soon as the shopper picks a method in the list, and dropped when it belongs to
 * another quote, so it applies at most once and never over the shopper's own choice.
 */
class CartButtonPreselect
{
    /** Checkout session key: the int id of the quote whose shopper chose Affirm with a cart button. */
    public const SESSION_KEY = 'hyva_affirm_preselect_quote_id';

    /**
     * A Magewire 3 class. Magewire 3 hydrates a component's public properties before boot(); Magewire 1 hydrates
     * them after boot() on the component's own update requests, so there the browser's copy of the method would
     * overwrite what boot() read from the quote.
     */
    // phpcs:ignore Magento2.PHP.LiteralNamespaces
    private const MAGEWIRE3_MARKER = 'Magewirephp\Magewire\Mechanisms\HandleComponents\HandleComponents';

    /**
     * @var bool|null Whether Magewire 3 is installed (looked up once).
     */
    private ?bool $magewire3 = null;

    /**
     * @param CheckoutSession $checkoutSession
     * @param CartRepositoryInterface $cartRepository
     * @param PaymentMethodManagementInterface $paymentMethodManagement
     * @param PaymentInterfaceFactory $paymentFactory
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly PaymentMethodManagementInterface $paymentMethodManagement,
        private readonly PaymentInterfaceFactory $paymentFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Select Affirm on the quote before the method list reads the quote's method.
     *
     * @param MethodList $subject
     * @return void
     */
    public function beforeBoot(MethodList $subject): void
    {
        try {
            $pending = $this->pendingQuoteId();
            if ($pending === null || $this->isOwnUpdateRequest($subject)) {
                return;
            }

            $quoteId = (int) $this->checkoutSession->getQuoteId();
            if ($quoteId !== $pending) {
                $this->forget();
                return;
            }

            if ($this->loadQuote($quoteId)->getPayment()->getMethod() === AffirmGateway::METHOD_CODE) {
                $this->forget();
                return;
            }

            if (!$this->isAvailable($quoteId)) {
                return;
            }

            $payment = $this->paymentFactory->create();
            $payment->setMethod(AffirmGateway::METHOD_CODE);
            $this->paymentMethodManagement->set($quoteId, $payment);
            // As Hyvä's own method selection does: the session's quote is the saved one.
            $this->checkoutSession->replaceQuote($this->loadQuote($quoteId));
        } catch (\Throwable $e) {
            // Never break the payment step: Magewire would skip the list's own boot() after an exception here.
            $this->logger->warning(
                'Hyva_CheckoutAffirm: the cart button choice of Affirm could not be applied.',
                ['error' => get_class($e) . ': ' . $e->getMessage()]
            );
        }

        $this->forget();
    }

    /**
     * The shopper picked a method in the list: from now on their choice wins.
     *
     * @param MethodList $subject
     * @param string $value
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeUpdatedMethod(MethodList $subject, string $value): void
    {
        $this->forget();
    }

    /**
     * Quote id recorded by a cart button, if any.
     *
     * @return int|null
     */
    private function pendingQuoteId(): ?int
    {
        $value = $this->checkoutSession->getData(self::SESSION_KEY);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * Remove the record, if any.
     *
     * @return void
     */
    private function forget(): void
    {
        try {
            if ($this->checkoutSession->getData(self::SESSION_KEY) !== null) {
                // SessionManager forwards uns*() calls to the session storage.
                $this->checkoutSession->unsetData(self::SESSION_KEY); // @phpstan-ignore method.notFound
            }
        } catch (\Throwable) {
            // Nothing to forget without a session.
        }
    }

    /**
     * The quote from the repository (the instance the checkout session and Hyvä's components share).
     *
     * @param int $quoteId
     * @return Quote
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \UnexpectedValueException
     */
    private function loadQuote(int $quoteId): Quote
    {
        $quote = $this->cartRepository->get($quoteId);
        if (!$quote instanceof Quote) {
            throw new \UnexpectedValueException('The cart repository returned no quote model.');
        }

        return $quote;
    }

    /**
     * Affirm is among the methods Magento offers for the quote (the list Hyvä Checkout evaluates as well).
     *
     * @param int $quoteId
     * @return bool
     */
    private function isAvailable(int $quoteId): bool
    {
        foreach ($this->paymentMethodManagement->getList($quoteId) as $method) {
            if ($method->getCode() === AffirmGateway::METHOD_CODE) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether this is a Magewire 1 update request of the method list itself.
     *
     * See MAGEWIRE3_MARKER. A list rendered fresh, on a page load or inside another component's update (Hyvä's
     * step navigation), is not one.
     *
     * @param MethodList $component
     * @return bool
     */
    private function isOwnUpdateRequest(MethodList $component): bool
    {
        $this->magewire3 ??= class_exists(self::MAGEWIRE3_MARKER);
        if ($this->magewire3 || !method_exists($component, 'getRequest')) {
            return false;
        }

        $request = $component->getRequest();

        return is_object($request) && method_exists($request, 'isSubsequent') && $request->isSubsequent() === true;
    }
}
