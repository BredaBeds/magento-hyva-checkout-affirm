<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Model\Checkout;

use DateTimeImmutable;
use Hyva\CheckoutAffirm\Model\Attempt\Attempt;
use Hyva\CheckoutAffirm\Model\Attempt\AttemptRepositoryInterface;
use Hyva\CheckoutAffirm\Model\Attempt\OwnerKeyInterface;
use Hyva\CheckoutAffirm\Model\Attempt\SnapshotInterface;
use Hyva\CheckoutAffirm\Model\Attempt\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Payment\Model\Checks\SpecificationFactory;
use Magento\Payment\Model\MethodInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\ResourceModel\Quote as QuoteResource;
use Psr\Log\LoggerInterface;
use RuntimeException;
use UnexpectedValueException;

/**
 * Prepares an Affirm checkout for a cart: the checks before Affirm opens, the attempt and the checkout object.
 *
 * Used by the Place Order request (Controller\Checkout\Prepare, available on every checkout step) and, for the
 * availability, totals and order number helpers, by the payment method's Magewire component (inline widget).
 */
class Preparation
{
    public const METHOD_CODE = 'affirm_gateway';

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
     * Prefix of the per-cart lock around attempt reuse, creation and supersession.
     */
    private const LOCK_PREFIX = 'hyva_affirm_prepare_';

    /**
     * @param CheckoutObjectBuilderInterface $checkoutObjectBuilder
     * @param ContactBuilder $contactBuilder
     * @param AttemptRepositoryInterface $attemptRepository
     * @param OwnerKeyInterface $ownerKey
     * @param SnapshotInterface $snapshot
     * @param QuoteResource $quoteResource
     * @param SpecificationFactory $specificationFactory
     * @param GuestCheckout $guestCheckout
     * @param LockManagerInterface $lockManager
     * @param LoggerInterface $logger
     * @param int $attemptLimit new attempts allowed per cart within $attemptWindowSeconds
     * @param int $attemptWindowSeconds
     * @param int $lockTimeoutSeconds how long a preparation waits for another one of the same cart
     */
    public function __construct(
        private readonly CheckoutObjectBuilderInterface $checkoutObjectBuilder,
        private readonly ContactBuilder $contactBuilder,
        private readonly AttemptRepositoryInterface $attemptRepository,
        private readonly OwnerKeyInterface $ownerKey,
        private readonly SnapshotInterface $snapshot,
        private readonly QuoteResource $quoteResource,
        private readonly SpecificationFactory $specificationFactory,
        private readonly GuestCheckout $guestCheckout,
        private readonly LockManagerInterface $lockManager,
        private readonly LoggerInterface $logger,
        private readonly int $attemptLimit = 10,
        private readonly int $attemptWindowSeconds = 3600,
        private readonly int $lockTimeoutSeconds = 10
    ) {
    }

    /**
     * The checkout object and its attempt for the cart.
     *
     * Re-checks what Magento would refuse at submit (guest checkout, cart errors, minimum order amount),
     * availability and the checkout data, reserves the order number, reuses or creates the attempt and builds
     * the checkout object.
     *
     * @param Quote $quote
     * @param string $mode "modal" or "redirect"
     * @return array{0: array<string,mixed>, 1: Attempt}
     * @throws LocalizedException with a shopper-safe message
     */
    public function prepare(Quote $quote, string $mode): array
    {
        if (!$quote->getId() || !$quote->hasItems()) {
            throw new LocalizedException(__('Your cart is empty or has expired. Please reload the page.'));
        }
        if (!$this->isSelected($quote)) {
            throw new LocalizedException(__('Your payment method changed. Please select Affirm again.'));
        }
        if (!$this->guestCheckout->isAllowed($quote)) {
            // Hyvä's own Place Order refuses this too (Checkout 3), but only after the Affirm application.
            throw new LocalizedException(__('Please sign in or create an account to place this order.'));
        }

        $this->collectTotals($quote);
        $this->assertSubmittable($quote);
        if (!$this->isAvailable($quote)) {
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

        $attempt = $this->withCartLock($quoteId, fn (): Attempt => $this->attemptFor($quote));

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
     * Whether Affirm is the quote's payment method.
     *
     * @param Quote $quote
     * @return bool
     */
    public function isSelected(Quote $quote): bool
    {
        return $quote->getPayment()->getMethod() === self::METHOD_CODE;
    }

    /**
     * Magento's availability of Affirm for the quote (false when Affirm is not its payment method).
     *
     * The method's isAvailable() (payment_method_is_active observers included) and the checks Magento applies
     * when it lists payment methods and places the order.
     *
     * @param Quote $quote
     * @return bool
     * @throws LocalizedException
     */
    public function isAvailable(Quote $quote): bool
    {
        if (!$this->isSelected($quote)) {
            return false;
        }
        $method = $quote->getPayment()->getMethodInstance();

        return $method->isAvailable($quote)
            && $this->specificationFactory->create(self::AVAILABILITY_CHECKS)->isApplicable($method, $quote);
    }

    /**
     * Collect the totals in this request (the builder reads collected amounts).
     *
     * @param Quote $quote
     * @return void
     */
    public function collectTotals(Quote $quote): void
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
    public function reserveOrderId(Quote $quote): string
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
    public function reservedOrderId(Quote $quote): string
    {
        $reserved = $quote->getReservedOrderId();

        return is_scalar($reserved) ? trim((string) $reserved) : '';
    }

    /**
     * What Magento's order submission would refuse, found before the shopper applies at Affirm.
     *
     * Hyvä's full validation rules are not run here: its reCAPTCHA rule would spend the shopper's confirmation.
     *
     * @param Quote $quote
     * @return void
     * @throws LocalizedException
     */
    private function assertSubmittable(Quote $quote): void
    {
        if ($quote->getData('has_error')) {
            throw new LocalizedException(
                __('Some items in your cart need attention. Please review your cart before paying with Affirm.')
            );
        }
        if (!$quote->validateMinimumAmount()) {
            throw new LocalizedException(
                __('Your cart is below the minimum order amount. Please add items before paying with Affirm.')
            );
        }
    }

    /**
     * Reserve the order number, then the reusable attempt of this cart, owner and cart content, or a new one.
     *
     * Runs under the cart lock, so preparations of one cart run one after the other. One that loaded the cart
     * before an earlier one saved its order number reserves a number of its own: the cart keeps the later number
     * and the earlier attempt is superseded.
     *
     * @param Quote $quote
     * @return Attempt
     * @throws LocalizedException
     */
    private function attemptFor(Quote $quote): Attempt
    {
        $this->reserveOrderId($quote);
        $ownerKeyHash = $this->ownerKey->getHash();
        $snapshotHash = $this->snapshot->hash($quote);

        return $this->reusableAttempt($quote, $ownerKeyHash, $snapshotHash)
            ?? $this->createAttempt($quote, $ownerKeyHash, $snapshotHash);
    }

    /**
     * Run $work while holding the cart's preparation lock.
     *
     * Two preparations of one cart then cannot supersede each other's new attempt.
     *
     * @param int $quoteId
     * @param callable $work returns the attempt
     * @return Attempt
     * @throws LocalizedException when another preparation holds the lock for too long
     */
    private function withCartLock(int $quoteId, callable $work): Attempt
    {
        $lock = self::LOCK_PREFIX . $quoteId;
        if (!$this->lockManager->lock($lock, $this->lockTimeoutSeconds)) {
            throw new LocalizedException(
                __('Affirm is still being prepared for this cart. Please try again in a moment.')
            );
        }
        try {
            return $work();
        } finally {
            $this->lockManager->unlock($lock);
        }
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

        $attempt = $this->attemptRepository->create(
            $quoteId,
            (int) $quote->getStoreId(),
            GuestCheckout::customerId($quote),
            $this->reservedOrderId($quote),
            $this->currency($preview),
            $this->total($preview),
            $snapshotHash,
            $ownerKeyHash,
            self::METHOD_CODE
        );
        $this->attemptRepository->supersedeOthersForQuote($quoteId, $attempt->attemptId);

        return $attempt;
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
}
