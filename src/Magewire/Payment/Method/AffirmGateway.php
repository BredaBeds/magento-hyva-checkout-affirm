<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Magewire\Payment\Method;

use Hyva\Checkout\Model\Magewire\Component\EvaluationInterface;
use Hyva\Checkout\Model\Magewire\Component\EvaluationResultFactory;
use Hyva\Checkout\Model\Magewire\Component\EvaluationResultInterface;
use Hyva\CheckoutAffirm\Model\Checkout\CheckoutObjectBuilderInterface;
use Hyva\CheckoutAffirm\Model\Checkout\Preparation;
use Hyva\CheckoutAffirm\ViewModel\Method;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;
use Magewirephp\Magewire\Component;
use Magewirephp\Magewire\Features\SupportMagewireBackwardsCompatibility\HandleBackwardsCompatibility;
use Psr\Log\LoggerInterface;

/**
 * Magewire component of the Affirm payment method body in Hyvä Checkout (Magewire 1 and 3).
 *
 * It carries the inline ("edu") widget and Hyvä's evaluation. Place Order does not use it: the method body is
 * rendered only on the step with the payment method list, and Place Order may sit on a later step, so the
 * checkout is prepared by Controller\Checkout\Prepare.
 *
 * Magewire lets the browser call every public method, so the surface is kept to: prepareInline() (action),
 * refreshInline() (listener target; only rebuilds the inline widget's object) and evaluateCompletion() (Hyvä's
 * evaluation hook). Public properties are visible to and writable by the browser: they carry results to the page
 * and are never read back as input.
 *
 * Magewire 3 (Hyvä Checkout 3) runs it as a native component, with or without Hyvä's Magewire 1 compatibility module
 * (Hyva_CheckoutV1bc): `$listeners` is read by Magewire 3 itself, lifecycle hooks such as mount() cannot be called
 * from the browser, and `$uncallables` (Magewire 1 only) is not needed there because evaluateCompletion() needs an
 * argument the browser cannot send. Magewire 1 (Hyvä Checkout 1.3) does not know the attribute and ignores it.
 */
#[HandleBackwardsCompatibility(enabled: false)]
class AffirmGateway extends Component implements EvaluationInterface
{
    public const METHOD_CODE = Preparation::METHOD_CODE;

    /**
     * Magewire component id: the method block's layout name.
     */
    public const COMPONENT_ID = 'checkout.payment.method.affirm_gateway';

    /**
     * Checkout object of the inline ("edu") widget; null hides the widget.
     *
     * @var array<string,mixed>|null
     */
    public ?array $inlineCheckout = null;

    /**
     * Incremented on every inline rebuild, so the browser can drop replies older than the widget it shows.
     *
     * @var int
     */
    public int $inlineVersion = 0;

    /**
     * Whether the inline widget is enabled.
     *
     * @var bool
     */
    public bool $edu = false;

    /**
     * Magewire 1: Hyvä runs the evaluation itself; it is not a browser action.
     *
     * @var string[]
     */
    protected array $uncallables = ['evaluateCompletion'];

    /**
     * Cart changes that move the inline widget's amounts (untyped like the parent property).
     *
     * @var array<string,string>
     */
    protected $listeners = [
        'shipping_method_selected' => 'refreshInline',
        'shipping_address_saved' => 'refreshInline',
        'shipping_address_activated' => 'refreshInline',
        'billing_address_saved' => 'refreshInline',
        'billing_address_activated' => 'refreshInline',
        'coupon_code_applied' => 'refreshInline',
        'coupon_code_revoked' => 'refreshInline',
        'payment_method_selected' => 'refreshInline',
    ];

    /**
     * Availability computed in this request (the component lives for one request).
     *
     * @var bool|null
     */
    private ?bool $available = null;

    /**
     * @param CheckoutSession $checkoutSession
     * @param CheckoutObjectBuilderInterface $checkoutObjectBuilder
     * @param Preparation $preparation
     * @param Method $method
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly CheckoutObjectBuilderInterface $checkoutObjectBuilder,
        private readonly Preparation $preparation,
        private readonly Method $method,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * First render: the widget setting.
     *
     * @return void
     */
    public function mount(): void
    {
        $this->edu = $this->method->isEduEnabled();
    }

    /**
     * Action: build the inline widget's checkout object.
     *
     * Reserves the order number when the cart has none yet (as Affirm's own inline checkout does; idempotent while
     * unused). Never creates checkout attempts.
     *
     * @return void
     */
    public function prepareInline(): void
    {
        $this->edu = $this->method->isEduEnabled();
        if (!$this->edu) {
            $this->clearInline();

            return;
        }

        $quote = $this->selectedQuote();
        if ($quote !== null) {
            $this->rebuildInline($quote, true);
        }
    }

    /**
     * Listener target: rebuild the inline widget's checkout object after a cart change.
     *
     * Free of side effects: no attempt, no reservation; before the first prepareInline() there is nothing to
     * rebuild. Variadic because Magewire 1 passes the event payload as one array and Magewire 3 as named
     * arguments; the payload is not used.
     *
     * @param mixed $args event payload, unused
     * @return void
     */
    public function refreshInline(mixed ...$args): void
    {
        $this->edu = $this->method->isEduEnabled();
        if (!$this->edu) {
            $this->clearInline();

            return;
        }

        $quote = $this->selectedQuote();
        if ($quote !== null) {
            $this->rebuildInline($quote, false);
        }
    }

    /**
     * Hyvä evaluation, run on every render: blocks Place Order while Affirm is selected but not available.
     *
     * Based on availability only, so a failed Place Order never blocks the retry.
     *
     * @param EvaluationResultFactory $resultFactory
     * @return EvaluationResultInterface
     */
    public function evaluateCompletion(EvaluationResultFactory $resultFactory): EvaluationResultInterface
    {
        try {
            $quote = $this->checkoutSession->getQuote();
            $available = !$this->preparation->isSelected($quote)
                || ($this->available ?? $this->computeAvailability($quote));
        } catch (\Throwable $e) {
            $this->logFailure('Hyva_CheckoutAffirm: Affirm availability could not be evaluated.', $e);
            $available = false;
        }

        if ($available) {
            return $resultFactory->createSuccess();
        }

        return $resultFactory->createErrorMessageEvent()
            ->withMessage((string) __('Affirm is not available for this cart. Please choose another payment method.'))
            ->withCustomEvent('payment:method:error');
    }

    /**
     * Rebuild the inline widget's object (null when Affirm cannot be offered) and bump its version.
     *
     * @param Quote $quote
     * @param bool $reserve whether the order number may be reserved
     * @return void
     */
    private function rebuildInline(Quote $quote, bool $reserve): void
    {
        $inline = null;
        try {
            $this->preparation->collectTotals($quote);
            if ($this->computeAvailability($quote)) {
                $orderId = $reserve
                    ? $this->preparation->reserveOrderId($quote)
                    : $this->preparation->reservedOrderId($quote);
                if ($orderId === '') {
                    return;
                }
                $inline = $this->checkoutObjectBuilder->build($quote, CheckoutObjectBuilderInterface::MODE_INLINE, '');
            }
        } catch (LocalizedException) { // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
            // Affirm cannot be offered for this cart (the builder logs why): the widget is removed.
        } catch (\Throwable $e) {
            $this->logFailure('Hyva_CheckoutAffirm: the inline Affirm checkout could not be built.', $e);
        }

        $this->inlineCheckout = $inline;
        $this->bumpInlineVersion();
    }

    /**
     * Remove the inline widget's object.
     *
     * @return void
     */
    private function clearInline(): void
    {
        if ($this->inlineCheckout !== null) {
            $this->inlineCheckout = null;
            $this->bumpInlineVersion();
        }
    }

    /**
     * Next inline version; the browser may have sent any value back.
     *
     * @return void
     */
    private function bumpInlineVersion(): void
    {
        $this->inlineVersion = $this->inlineVersion >= 0 && $this->inlineVersion < PHP_INT_MAX
            ? $this->inlineVersion + 1
            : 1;
    }

    /**
     * The session quote when Affirm is its payment method.
     *
     * @return Quote|null
     */
    private function selectedQuote(): ?Quote
    {
        try {
            $quote = $this->checkoutSession->getQuote();

            return $quote->getId() && $this->preparation->isSelected($quote) ? $quote : null;
        } catch (\Throwable $e) {
            $this->logFailure('Hyva_CheckoutAffirm: the checkout quote could not be loaded.', $e);

            return null;
        }
    }

    /**
     * Magento's availability of Affirm for the quote, remembered for this request.
     *
     * @param Quote $quote
     * @return bool
     * @throws LocalizedException
     */
    private function computeAvailability(Quote $quote): bool
    {
        return $this->available = $this->preparation->isAvailable($quote);
    }

    /**
     * Log an unexpected failure by class and location only: messages can carry customer data.
     *
     * @param string $message
     * @param \Throwable $exception
     * @return void
     */
    private function logFailure(string $message, \Throwable $exception): void
    {
        $this->logger->error($message, [
            'exception' => get_class($exception),
            'location' => $exception->getFile() . ':' . $exception->getLine(),
        ]);
    }
}
