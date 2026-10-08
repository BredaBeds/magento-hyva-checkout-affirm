<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Magewire\Payment\Method;

use DateTimeImmutable;
use Hyva\Checkout\Model\Magewire\Component\EvaluationInterface;
use Hyva\Checkout\Model\Magewire\Component\EvaluationResultFactory;
use Hyva\Checkout\Model\Magewire\Component\EvaluationResultInterface;
use Hyva\CheckoutAffirm\Model\Attempt\Attempt;
use Hyva\CheckoutAffirm\Model\Attempt\AttemptRepositoryInterface;
use Hyva\CheckoutAffirm\Model\Attempt\OwnerKeyInterface;
use Hyva\CheckoutAffirm\Model\Attempt\SnapshotInterface;
use Hyva\CheckoutAffirm\Model\Attempt\State;
use Hyva\CheckoutAffirm\Model\Checkout\CheckoutObjectBuilderInterface;
use Hyva\CheckoutAffirm\Model\Checkout\ContactBuilder;
use Hyva\CheckoutAffirm\ViewModel\Method;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Model\Checks\SpecificationFactory;
use Magento\Payment\Model\MethodInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\ResourceModel\Quote as QuoteResource;
use Magewirephp\Magewire\Component;
use Psr\Log\LoggerInterface;
use RuntimeException;
use UnexpectedValueException;

/**
 * Magewire component of the Affirm payment method in Hyvä Checkout (Magewire 1 and 3).
 *
 * Magewire lets the browser call every public method, so the surface is kept to: prepareCheckout() and
 * prepareInline() (actions), refreshInline() (listener target; only rebuilds the inline widget's object) and
 * evaluateCompletion() (Hyvä's evaluation hook). Public properties are visible to and writable by the browser:
 * they carry results to the page and are never read back as input.
 */
class AffirmGateway extends Component implements EvaluationInterface
{
    public const METHOD_CODE = 'affirm_gateway';

    /**
     * Magewire component id: the method block's layout name.
     */
    public const COMPONENT_ID = 'checkout.payment.method.affirm_gateway';

    /**
     * The checks Magento applies when it lists payment methods and when it places the order.
     */
    private const AVAILABILITY_CHECKS = [
        MethodInterface::CHECK_USE_CHECKOUT,
        MethodInterface::CHECK_USE_FOR_COUNTRY,
        MethodInterface::CHECK_USE_FOR_CURRENCY,
        MethodInterface::CHECK_ORDER_TOTAL_MIN_MAX,
        MethodInterface::CHECK_ZERO_TOTAL,
    ];

    /**
     * Affirm checkout object for the prepared attempt (set by prepareCheckout()).
     *
     * @var array<string, mixed>|null
     */
    public ?array $checkout = null;

    /**
     * Attempt the checkout object's return URLs point to (set by prepareCheckout()).
     *
     * @var string|null
     */
    public ?string $attemptId = null;

    /**
     * Shopper-safe reason prepareCheckout() did not prepare a checkout.
     *
     * @var string|null
     */
    public ?string $errorMessage = null;

    /**
     * Checkout object of the inline ("edu") widget; null hides the widget.
     *
     * @var array<string, mixed>|null
     */
    public ?array $inlineCheckout = null;

    /**
     * Incremented on every inline rebuild, so the browser can drop replies older than the widget it shows.
     *
     * @var int
     */
    public int $inlineVersion = 0;

    /**
     * Checkout flow: "modal" or "redirect".
     *
     * @var string
     */
    public string $mode = Method::MODE_MODAL;

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
     * @var array<string, string>
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
     * @param ContactBuilder $contactBuilder
     * @param AttemptRepositoryInterface $attemptRepository
     * @param OwnerKeyInterface $ownerKey
     * @param SnapshotInterface $snapshot
     * @param QuoteResource $quoteResource
     * @param SpecificationFactory $specificationFactory
     * @param Method $method
     * @param LoggerInterface $logger
     * @param int $attemptLimit new attempts allowed per cart within $attemptWindowSeconds
     * @param int $attemptWindowSeconds
     */
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly CheckoutObjectBuilderInterface $checkoutObjectBuilder,
        private readonly ContactBuilder $contactBuilder,
        private readonly AttemptRepositoryInterface $attemptRepository,
        private readonly OwnerKeyInterface $ownerKey,
        private readonly SnapshotInterface $snapshot,
        private readonly QuoteResource $quoteResource,
        private readonly SpecificationFactory $specificationFactory,
        private readonly Method $method,
        private readonly LoggerInterface $logger,
        private readonly int $attemptLimit = 10,
        private readonly int $attemptWindowSeconds = 3600
    ) {
    }

    /**
     * First render: the configured flow and widget setting.
     *
     * @return void
     */
    public function mount(): void
    {
        $this->mode = $this->method->getMode();
        $this->edu = $this->method->isEduEnabled();
    }

    /**
     * Action: prepare an Affirm checkout for the current cart.
     *
     * Re-checks availability and the checkout data, reserves the order number, reuses or creates the checkout
     * attempt and builds the checkout object. Sets checkout and attemptId, or errorMessage; never throws.
     *
     * @return void
     */
    public function prepareCheckout(): void
    {
        $this->checkout = null;
        $this->attemptId = null;
        $this->errorMessage = null;

        try {
            $this->mode = $this->method->getMode();
            $quote = $this->checkoutSession->getQuote();
            [$checkout, $attempt] = $this->prepare($quote, $this->mode);
            $this->checkout = $checkout;
            $this->attemptId = $attempt->attemptId;
        } catch (LocalizedException $e) {
            $this->errorMessage = $e->getMessage();
        } catch (\Throwable $e) {
            $this->logFailure('Hyva_CheckoutAffirm: the Affirm checkout could not be prepared.', $e);
            $this->errorMessage = (string) __(
                'Affirm is not available right now. Please try again or choose another payment method.'
            );
        }
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
     * Based on availability only, never on a previous errorMessage (a stale error would block the retry).
     *
     * @param EvaluationResultFactory $resultFactory
     * @return EvaluationResultInterface
     */
    public function evaluateCompletion(EvaluationResultFactory $resultFactory): EvaluationResultInterface
    {
        try {
            $quote = $this->checkoutSession->getQuote();
            $available = !$this->isSelected($quote) || ($this->available ?? $this->computeAvailability($quote));
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
     * The checkout object and its attempt.
     *
     * @param Quote $quote
     * @param string $mode
     * @return array{0: array<string, mixed>, 1: Attempt}
     * @throws LocalizedException with a shopper-safe message
     */
    private function prepare(Quote $quote, string $mode): array
    {
        if (!$quote->getId() || !$quote->hasItems()) {
            throw new LocalizedException(__('Your cart is empty or has expired. Please reload the page.'));
        }
        if (!$this->isSelected($quote)) {
            throw new LocalizedException(__('Your payment method changed. Please select Affirm again.'));
        }

        $this->collectTotals($quote);
        if (!$this->computeAvailability($quote)) {
            throw new LocalizedException(
                __('Affirm is not available for this cart. Please choose another payment method.')
            );
        }
        // Email, addresses and the shipping method of physical carts, before anything is written.
        $this->contactBuilder->build($quote);

        $quoteId = (int) $quote->getId();
        if ($this->attemptRepository->hasUnresolvedForQuote($quoteId)) {
            throw new LocalizedException(__(
                'Your previous Affirm payment for this cart is still being confirmed. Please reload the page shortly.'
            ));
        }

        $this->reserveOrderId($quote);
        $ownerKeyHash = $this->ownerKey->getHash();
        $snapshotHash = $this->snapshot->hash($quote);

        $attempt = $this->reusableAttempt($quote, $ownerKeyHash, $snapshotHash)
            ?? $this->createAttempt($quote, $ownerKeyHash, $snapshotHash);

        $checkout = $this->checkoutObjectBuilder->build($quote, $mode, $attempt->attemptId);
        if ($this->total($checkout) !== $attempt->grandTotalCents
            || $this->currency($checkout) !== $attempt->currency
        ) {
            // Unreachable for an unchanged cart (the snapshot covers the totals); never send amounts the attempt
            // would not verify.
            $this->attemptRepository->transition(
                $attempt->attemptId,
                State::open(),
                State::FAILED,
                ['failure_reason' => Attempt::REASON_CART_CHANGED]
            );
            $this->logger->warning('Hyva_CheckoutAffirm: checkout object and attempt totals differ.', [
                'quote_id' => $quoteId,
            ]);

            throw new LocalizedException(
                __('Your cart changed while Affirm was being prepared. Please place the order again.')
            );
        }

        return [$checkout, $attempt];
    }

    /**
     * The latest initiated attempt of this cart, owner and cart content, when it can be sent to Affirm again.
     *
     * @param Quote $quote
     * @param string $ownerKeyHash
     * @param string $snapshotHash
     * @return Attempt|null
     */
    private function reusableAttempt(Quote $quote, string $ownerKeyHash, string $snapshotHash): ?Attempt
    {
        $attempt = $this->attemptRepository->findReusable((int) $quote->getId(), $ownerKeyHash, $snapshotHash);
        if ($attempt === null
            || $attempt->reservedIncrementId !== $this->reservedOrderId($quote)
            || $attempt->storeId !== (int) $quote->getStoreId()
            || $attempt->paymentMethod !== self::METHOD_CODE
        ) {
            return null;
        }

        return $attempt;
    }

    /**
     * A new attempt, after the throttle and with the totals of a checkout object built for the cart.
     *
     * The preview build declines carts Affirm cannot take before a row is written; the previous open attempt of
     * the cart is superseded so only the latest preparation can be confirmed.
     *
     * @param Quote $quote
     * @param string $ownerKeyHash
     * @param string $snapshotHash
     * @return Attempt
     * @throws LocalizedException
     */
    private function createAttempt(Quote $quote, string $ownerKeyHash, string $snapshotHash): Attempt
    {
        $quoteId = (int) $quote->getId();
        $since = new DateTimeImmutable('@' . (time() - $this->attemptWindowSeconds));
        if ($this->attemptRepository->countRecentForQuote($quoteId, $since) >= $this->attemptLimit) {
            $this->logger->warning('Hyva_CheckoutAffirm: Affirm checkout attempts throttled.', [
                'quote_id' => $quoteId,
                'limit' => $this->attemptLimit,
            ]);

            throw new LocalizedException(__(
                'Too many Affirm payment attempts for this cart. Please try again later or use another payment method.'
            ));
        }

        $preview = $this->checkoutObjectBuilder->build($quote, CheckoutObjectBuilderInterface::MODE_INLINE, '');

        $latest = $this->attemptRepository->findLatestForQuote($quoteId);
        if ($latest !== null && $latest->state->isOpen()) {
            $this->attemptRepository->transition(
                $latest->attemptId,
                State::open(),
                State::FAILED,
                ['failure_reason' => Attempt::REASON_SUPERSEDED]
            );
        }

        $customerId = $quote->getData('customer_id');

        return $this->attemptRepository->create(
            $quoteId,
            (int) $quote->getStoreId(),
            is_numeric($customerId) && (int) $customerId > 0 ? (int) $customerId : null,
            $this->reservedOrderId($quote),
            $this->currency($preview),
            $this->total($preview),
            $snapshotHash,
            $ownerKeyHash,
            self::METHOD_CODE
        );
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
            $this->collectTotals($quote);
            if ($this->computeAvailability($quote)) {
                $orderId = $reserve ? $this->reserveOrderId($quote) : $this->reservedOrderId($quote);
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

            return $quote->getId() && $this->isSelected($quote) ? $quote : null;
        } catch (\Throwable $e) {
            $this->logFailure('Hyva_CheckoutAffirm: the checkout quote could not be loaded.', $e);

            return null;
        }
    }

    /**
     * Whether Affirm is the quote's payment method.
     *
     * @param Quote $quote
     * @return bool
     */
    private function isSelected(Quote $quote): bool
    {
        return $quote->getPayment()->getMethod() === self::METHOD_CODE;
    }

    /**
     * Magento's availability of Affirm for the quote.
     *
     * The method's isAvailable() (payment_method_is_active observers included) and the checks Magento applies
     * when it lists payment methods and places the order.
     *
     * @param Quote $quote
     * @return bool
     * @throws LocalizedException
     */
    private function computeAvailability(Quote $quote): bool
    {
        $available = false;
        if ($this->isSelected($quote)) {
            $method = $quote->getPayment()->getMethodInstance();
            $available = $method->isAvailable($quote)
                && $this->specificationFactory->create(self::AVAILABILITY_CHECKS)->isApplicable($method, $quote);
        }

        return $this->available = $available;
    }

    /**
     * Collect the totals in this request (the builder reads collected amounts).
     *
     * @param Quote $quote
     * @return void
     */
    private function collectTotals(Quote $quote): void
    {
        $quote->setTotalsCollectedFlag(false); // @phpstan-ignore method.notFound
        $quote->collectTotals();
    }

    /**
     * Reserve the order number (kept while unused) and save the quote when it changed.
     *
     * @param Quote $quote
     * @return string the reserved order number
     * @throws RuntimeException when no number could be reserved
     */
    private function reserveOrderId(Quote $quote): string
    {
        $before = $this->reservedOrderId($quote);
        $quote->reserveOrderId();
        $reserved = $this->reservedOrderId($quote);
        if ($reserved === '') {
            throw new RuntimeException('No order number could be reserved for the cart.');
        }
        if ($reserved !== $before) {
            $this->quoteResource->save($quote);
        }

        return $reserved;
    }

    /**
     * The quote's reserved order number, or ''.
     *
     * @param Quote $quote
     * @return string
     */
    private function reservedOrderId(Quote $quote): string
    {
        $reserved = $quote->getReservedOrderId();

        return is_scalar($reserved) ? trim((string) $reserved) : '';
    }

    /**
     * Total of a checkout object, in cents.
     *
     * @param mixed[] $checkout
     * @return int
     * @throws UnexpectedValueException
     */
    private function total(array $checkout): int
    {
        if (!is_int($checkout['total'] ?? null)) {
            throw new UnexpectedValueException('The checkout object has no total.');
        }

        return $checkout['total'];
    }

    /**
     * Currency of a checkout object.
     *
     * @param mixed[] $checkout
     * @return string
     * @throws UnexpectedValueException
     */
    private function currency(array $checkout): string
    {
        $currency = $checkout['currency'] ?? null;
        if (!is_string($currency) || $currency === '') {
            throw new UnexpectedValueException('The checkout object has no currency.');
        }

        return $currency;
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
