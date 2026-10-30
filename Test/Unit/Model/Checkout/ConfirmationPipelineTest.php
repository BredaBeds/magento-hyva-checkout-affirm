<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Test\Unit\Model\Checkout;

use Closure;
use DateTimeInterface;
use Hyva\CheckoutAffirm\Model\Attempt\AdminNotifier;
use Hyva\CheckoutAffirm\Model\Attempt\Attempt;
use Hyva\CheckoutAffirm\Model\Attempt\AttemptRepositoryInterface;
use Hyva\CheckoutAffirm\Model\Attempt\Ownership;
use Hyva\CheckoutAffirm\Model\Attempt\SnapshotInterface;
use Hyva\CheckoutAffirm\Model\Attempt\StaleAttemptPolicy;
use Hyva\CheckoutAffirm\Model\Attempt\State;
use Hyva\CheckoutAffirm\Model\Checkout\AttemptRecovery;
use Hyva\CheckoutAffirm\Model\Checkout\ConfirmationOutcome;
use Hyva\CheckoutAffirm\Model\Checkout\ConfirmationPipeline;
use Hyva\CheckoutAffirm\Model\Checkout\OrderPlacement;
use Hyva\CheckoutAffirm\Model\Checkout\SuccessSession;
use Hyva\CheckoutAffirm\Model\Checkout\VerificationResult;
use Hyva\CheckoutAffirm\Model\Checkout\VerifierInterface;
use InvalidArgumentException;
use LogicException;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Payment\Model\MethodInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\CartLockedException;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Payment;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pipeline behaviour against an in-memory attempt store with real compare-and-swap semantics.
 */
class ConfirmationPipelineTest extends TestCase
{
    private const ATTEMPT_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const OTHER_ATTEMPT_ID = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const TOKEN = 'MARKERTOKEN0001';
    private const OTHER_TOKEN = 'MARKERTOKEN0002';
    private const QUOTE_ID = 42;
    private const STORE_ID = 1;
    private const RESERVED = '000000123';
    private const ORDER_ID = 555;
    private const NOW = 1800000000;

    /**
     * @var array<string, Attempt>
     */
    private array $rows = [];

    /**
     * @var list<string> transitions as "from>to"
     */
    private array $transitions = [];

    /**
     * Runs before each compare-and-swap; may change rows to simulate a concurrent request.
     *
     * @var (Closure(string, State): void)|null
     */
    private ?Closure $race = null;

    /**
     * @var AttemptRepositoryInterface
     */
    private AttemptRepositoryInterface $repository;

    /**
     * @var bool
     */
    private bool $owner = true;

    /**
     * @var int
     */
    private int $quoteId = self::QUOTE_ID;

    /**
     * @var bool
     */
    private bool $quoteActive = true;

    /**
     * @var int
     */
    private int $quoteStoreId = self::STORE_ID;

    /**
     * @var int
     */
    private int $currentStoreId = self::STORE_ID;

    /**
     * @var string
     */
    private string $paymentMethod = 'affirm_gateway';

    /**
     * @var string
     */
    private string $currentSnapshot;

    /**
     * @var bool
     */
    private bool $available = true;

    /**
     * @var VerificationResult
     */
    private VerificationResult $verification;

    /**
     * @var list<array{attempt_state: State, stored_state: State, token_on_payment: mixed}>
     */
    private array $verifyCalls = [];

    /**
     * @var Closure(): int
     */
    private Closure $placement;

    /**
     * @var int
     */
    private int $placementCalls = 0;

    /**
     * @var (Closure(): ?OrderInterface)|null
     */
    private ?Closure $orderLookup = null;

    /**
     * @var array{quote_id: int, method: string, attempt_id: string|null} what the found order carries
     */
    private array $orderOrigin = [
        'quote_id' => self::QUOTE_ID,
        'method' => 'affirm_gateway',
        'attempt_id' => self::ATTEMPT_ID,
    ];

    /**
     * @var list<array{0: string, 1: string, 2: int}> place() calls: attempt id, reserved number, quote id
     */
    private array $placeCalls = [];

    /**
     * @var (Closure(): void)|null
     */
    private ?Closure $collectTotals = null;

    /**
     * @var (Closure(): void)|null
     */
    private ?Closure $observer = null;

    /**
     * @var int
     */
    private int $paymentSaves = 0;

    /**
     * @var list<string>
     */
    private array $calls = [];

    /**
     * @var list<array{0: string, 1: array<string, mixed>}>
     */
    private array $events = [];

    /**
     * @var list<string>
     */
    private array $notifications = [];

    /**
     * @var list<array{0: string, 1: string, 2: array<string, mixed>}>
     */
    private array $logs = [];

    /**
     * @var list<ConfirmationOutcome>
     */
    private array $outcomes = [];

    /**
     * @var Payment&MockObject
     */
    private Payment $payment;

    /**
     * @var Quote&MockObject
     */
    private Quote $quote;

    /**
     * @var OrderInterface&MockObject
     */
    private OrderInterface $order;

    /**
     * @var CartRepositoryInterface&MockObject
     */
    private CartRepositoryInterface $cartRepository;

    /**
     * @var ConfirmationPipeline
     */
    private ConfirmationPipeline $pipeline;

    protected function setUp(): void
    {
        $this->repository = $this->memoryRepository();
        $this->currentSnapshot = $this->snapshotHash();
        $this->verification = VerificationResult::success('confirmed', self::RESERVED, 123456, 'USD');
        $this->placement = function (): int {
            // Affirm's client plugin marks the attempt before the authorize request.
            $this->repository->transition(self::ATTEMPT_ID, [State::PLACING], State::AUTHORIZING);

            return self::ORDER_ID;
        };

        $this->payment = $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getMethod', 'getMethodInstance', 'save'])
            ->getMock();
        $this->payment->method('getMethod')->willReturnCallback(fn (): string => $this->paymentMethod);
        $method = $this->createMock(MethodInterface::class);
        $method->method('isAvailable')->willReturnCallback(fn (): bool => $this->available);
        $this->payment->method('getMethodInstance')->willReturn($method);
        $this->payment->method('save')->willReturnCallback(function () {
            $this->paymentSaves++;

            return $this->payment;
        });

        $this->quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getIsActive', 'getStoreId', 'getPayment', 'collectTotals'])
            ->getMock();
        $this->quote->method('getId')->willReturnCallback(fn (): int => $this->quoteId);
        $this->quote->method('getIsActive')->willReturnCallback(fn (): bool => $this->quoteActive);
        $this->quote->method('getStoreId')->willReturnCallback(fn (): int => $this->quoteStoreId);
        $this->quote->method('getPayment')->willReturn($this->payment);
        $this->quote->method('collectTotals')->willReturnCallback(function () {
            $this->calls[] = 'collectTotals:' . var_export($this->quote->getData('totals_collected_flag'), true);
            if ($this->collectTotals !== null) {
                ($this->collectTotals)();
            }

            return $this->quote;
        });

        $orderPayment = $this->createMock(OrderPaymentInterface::class);
        $orderPayment->method('getMethod')->willReturnCallback(fn (): string => $this->orderOrigin['method']);
        $orderPayment->method('getAdditionalInformation')->willReturnCallback(
            fn (): array => $this->orderOrigin['attempt_id'] === null
                ? []
                : ['hyva_affirm_attempt_id' => $this->orderOrigin['attempt_id']]
        );
        $this->order = $this->createMock(OrderInterface::class);
        $this->order->method('getEntityId')->willReturn(self::ORDER_ID);
        $this->order->method('getIncrementId')->willReturn(self::RESERVED);
        $this->order->method('getStatus')->willReturn('processing');
        $this->order->method('getQuoteId')->willReturnCallback(fn (): int => $this->orderOrigin['quote_id']);
        $this->order->method('getPayment')->willReturn($orderPayment);

        $this->cartRepository = $this->createMock(CartRepositoryInterface::class);

        $ownership = $this->createMock(Ownership::class);
        $ownership->method('isOwner')->willReturnCallback(fn (): bool => $this->owner);

        $snapshot = $this->createMock(SnapshotInterface::class);
        $snapshot->method('hash')->willReturnCallback(fn (): string => $this->currentSnapshot);

        $verifier = $this->createMock(VerifierInterface::class);
        $verifier->method('verify')->willReturnCallback(function (Attempt $attempt, string $token): VerificationResult {
            $this->assertSame(self::TOKEN, $token);
            $this->verifyCalls[] = [
                'attempt_state' => $attempt->state,
                'stored_state' => $this->rows[self::ATTEMPT_ID]->state,
                'token_on_payment' => $this->payment->getAdditionalInformation('checkout_token'),
            ];

            return $this->verification;
        });

        $orderPlacement = $this->createMock(OrderPlacement::class);
        $orderPlacement->method('place')->willReturnCallback(
            function (Quote $quote, string $attemptId, string $reservedIncrementId): int {
                $this->assertSame($this->quote, $quote);
                $this->assertSame(self::ATTEMPT_ID, $attemptId);
                $this->placeCalls[] = [$attemptId, $reservedIncrementId, (int) $quote->getId()];
                $this->placementCalls++;

                return ($this->placement)();
            }
        );
        $orderPlacement->method('findOrder')->willReturnCallback(function (string $incrementId, int $storeId) {
            $this->assertSame([self::RESERVED, self::STORE_ID], [$incrementId, $storeId]);
            $this->calls[] = 'findOrder';

            return $this->orderLookup === null ? null : ($this->orderLookup)();
        });
        $orderPlacement->method('getOrder')->willReturnCallback(
            fn (int $orderId): ?OrderInterface => $orderId === self::ORDER_ID ? $this->order : null
        );
        $orderPlacement->method('deactivateQuote')->willReturnCallback(function (Quote $quote): bool {
            $this->assertSame($this->quote, $quote);
            $this->calls[] = 'deactivateQuote';

            return true;
        });

        $successSession = $this->createMock(SuccessSession::class);
        $successSession->method('armForOrder')->willReturnCallback(function (int $quoteId, OrderInterface $order) {
            $this->assertSame(self::QUOTE_ID, $quoteId);
            $this->assertSame($this->order, $order);
            $this->calls[] = 'armForOrder';
        });
        $successSession->method('arm')->willReturnCallback(function () {
            $this->calls[] = 'arm';
        });

        $adminNotifier = $this->createMock(AdminNotifier::class);
        $adminNotifier->method('notifyUncertain')->willReturnCallback(function (string $reference) {
            $this->notifications[] = $reference;
        });

        $eventManager = $this->createMock(EventManagerInterface::class);
        $eventManager->method('dispatch')->willReturnCallback(function (string $name, array $data = []) {
            $this->events[] = [$name, $data];
            if ($this->observer !== null) {
                ($this->observer)();
            }
        });

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturnCallback(fn (): int => $this->currentStoreId);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn(self::NOW);

        $logger = $this->createMock(LoggerInterface::class);
        foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'] as $level) {
            $logger->method($level)->willReturnCallback(function ($message, array $context = []) use ($level) {
                $this->logs[] = [$level, (string) $message, $context];
            });
        }

        $stalePolicy = new StaleAttemptPolicy();
        $this->pipeline = new ConfirmationPipeline(
            $this->repository,
            $ownership,
            $this->cartRepository,
            $snapshot,
            $verifier,
            $orderPlacement,
            $successSession,
            new AttemptRecovery($this->repository, $orderPlacement, $adminNotifier, $stalePolicy, $logger),
            $eventManager,
            $storeManager,
            $dateTime,
            $stalePolicy,
            $logger
        );
    }

    protected function tearDown(): void
    {
        // Neither the token nor the attempt id may leave the pipeline in a message or a log line.
        $exposed = json_encode([$this->logs, array_map(fn ($o) => $o->message, $this->outcomes)]);
        $this->assertIsString($exposed);
        $this->assertStringNotContainsString(self::TOKEN, $exposed);
        $this->assertStringNotContainsString(self::ATTEMPT_ID, $exposed);
    }

    public function testUnknownAttemptFails(): void
    {
        $outcome = $this->confirm();

        $this->assertTrue($outcome->isFailed());
        $this->assertSame(ConfirmationOutcome::REASON_NOT_FOUND, $outcome->reason);
        $this->assertNotSame('', $outcome->message);
    }

    public function testForeignAttemptIsNotTouched(): void
    {
        $this->store(State::INITIATED);
        $this->owner = false;

        $outcome = $this->confirm();

        $this->assertTrue($outcome->isFailed());
        $this->assertSame(ConfirmationOutcome::REASON_NOT_OWNER, $outcome->reason);
        $this->assertSame(State::INITIATED, $this->stored()->state);
        $this->assertSame([], $this->verifyCalls);
        $this->assertSame([], $this->transitions);
    }

    public function testForeignPlacedAttemptIsNotReplayed(): void
    {
        $this->store(State::PLACED, ['order_id' => self::ORDER_ID, 'order_increment_id' => self::RESERVED]);
        $this->owner = false;

        $outcome = $this->confirm();

        $this->assertTrue($outcome->isFailed());
        $this->assertSame(ConfirmationOutcome::REASON_NOT_OWNER, $outcome->reason);
    }

    public function testPlacedAttemptIsReplayedWithoutPlacingAgain(): void
    {
        $this->store(State::PLACED, ['order_id' => self::ORDER_ID, 'order_increment_id' => '000000124']);

        $outcome = $this->confirm();

        $this->assertTrue($outcome->isPlaced());
        $this->assertTrue($outcome->replay);
        $this->assertSame(self::ORDER_ID, $outcome->orderId);
        $this->assertSame('000000124', $outcome->incrementId);
        $this->assertSame(self::QUOTE_ID, $outcome->quoteId);
        $this->assertSame(0, $this->placementCalls);
        $this->assertSame([], $this->events);
        $this->assertSame([], $this->verifyCalls);
    }

    public function testPlacedAttemptWithoutOrderIdsNeedsReview(): void
    {
        $this->store(State::PLACED);

        $outcome = $this->confirm();

        $this->assertTrue($outcome->isUncertain());
        $this->assertSame(self::RESERVED, $outcome->incrementId);
    }

    public function testAttemptBeingPlacedIsInProgress(): void
    {
        // Changed 60 seconds ago: the placement is still running.
        foreach ([State::PLACING, State::AUTHORIZING] as $state) {
            $this->store($state);

            $outcome = $this->confirm();

            $this->assertTrue($outcome->isInProgress(), $state->value);
            $this->assertSame(ConfirmationOutcome::REASON_IN_PROGRESS, $outcome->reason);
            $this->assertSame($state, $this->stored()->state);
        }
        $this->assertSame(0, $this->placementCalls);
    }

    public function testAbandonedPlacementShowsTheOrderReferenceInsteadOfRefreshingForever(): void
    {
        foreach ([State::PLACING, State::AUTHORIZING] as $state) {
            // Unchanged for exactly the stale threshold: its process died.
            $this->store($state, ['updated_at' => gmdate('Y-m-d H:i:s', self::NOW - 900)]);

            $outcome = $this->confirm();

            $this->assertTrue($outcome->isUncertain(), $state->value);
            $this->assertSame(self::RESERVED, $outcome->incrementId);
            $this->assertStringContainsString(self::RESERVED, $outcome->message);
            // Read-only: the escalation cron job settles the attempt and notifies the merchant.
            $this->assertSame($state, $this->stored()->state);
        }
        $this->assertSame([], $this->transitions);
        $this->assertSame([], $this->notifications);
        $this->assertSame(0, $this->placementCalls);
        $this->assertSame([], $this->verifyCalls);
    }

    public function testPlacementJustUnderTheStaleThresholdIsStillInProgress(): void
    {
        $this->store(State::AUTHORIZING, ['updated_at' => gmdate('Y-m-d H:i:s', self::NOW - 899)]);

        $this->assertTrue($this->confirm()->isInProgress());
    }

    public function testUnreadableLastChangeKeepsThePlacementInProgress(): void
    {
        $this->store(State::PLACING, ['updated_at' => 'yesterday']);

        $this->assertTrue($this->confirm()->isInProgress());
    }

    public function testUncertainAttemptReportsTheOrderReference(): void
    {
        $this->store(State::UNCERTAIN);

        $outcome = $this->confirm();

        $this->assertTrue($outcome->isUncertain());
        $this->assertSame(self::RESERVED, $outcome->incrementId);
        $this->assertStringContainsString(self::RESERVED, $outcome->message);
        $this->assertSame([], $this->notifications);
    }

    public function testFinishedAttemptReturnsItsReason(): void
    {
        $this->store(State::FAILED, ['failure_reason' => Attempt::REASON_CANCELLED]);
        $cancelled = $this->confirm();
        $this->store(State::RESOLVED, ['failure_reason' => Attempt::REASON_RESOLVED_VOIDED]);
        $voided = $this->confirm();

        $this->assertTrue($cancelled->isFailed());
        $this->assertSame(Attempt::REASON_CANCELLED, $cancelled->reason);
        $this->assertTrue($voided->isFailed());
        $this->assertSame(Attempt::REASON_RESOLVED_VOIDED, $voided->reason);
        $this->assertNotSame($cancelled->message, $voided->message);
        $this->assertSame([], $this->verifyCalls);
    }

    public function testExpiredAttemptFails(): void
    {
        $this->store(State::INITIATED, ['expires_at' => gmdate('Y-m-d H:i:s', self::NOW - 1)]);

        $outcome = $this->confirm();

        $this->assertFailedWith(Attempt::REASON_EXPIRED, $outcome);
        $this->assertSame([], $this->verifyCalls);
    }

    public function testChangedCartFails(): void
    {
        $this->store(State::INITIATED);
        $this->currentSnapshot = hash('sha256', 'another cart');

        $outcome = $this->confirm($this->quote);

        $this->assertFailedWith(Attempt::REASON_CART_CHANGED, $outcome);
        $this->assertSame(['collectTotals:false'], $this->calls, 'Totals are recollected before hashing.');
        $this->assertSame([], $this->verifyCalls);
    }

    public function testQuoteThatIsNoLongerTheAttemptsCartCountsAsChanged(): void
    {
        $cases = [
            'inactive quote' => fn () => $this->quoteActive = false,
            'other store' => fn () => $this->quoteStoreId = 2,
            'other current store' => fn () => $this->currentStoreId = 2,
            'other payment method' => fn () => $this->paymentMethod = 'checkmo',
            'other quote' => fn () => $this->quoteId = 43,
        ];
        foreach ($cases as $case => $mutate) {
            $this->resetCart();
            $this->store(State::INITIATED);
            $mutate();

            $outcome = $this->confirm($this->quote);

            $this->assertFailedWith(Attempt::REASON_CART_CHANGED, $outcome, $case);
        }
        $this->assertSame([], $this->verifyCalls);
    }

    public function testMissingQuoteCountsAsChanged(): void
    {
        $this->store(State::INITIATED);
        $this->cartRepository->method('get')->willThrowException(new NoSuchEntityException());

        $this->assertFailedWith(Attempt::REASON_CART_CHANGED, $this->confirm());
    }

    public function testUnavailableMethodFails(): void
    {
        $this->store(State::INITIATED);
        $this->available = false;

        $outcome = $this->confirm($this->quote);

        $this->assertFailedWith(Attempt::REASON_UNAVAILABLE, $outcome);
        $this->assertSame([], $this->verifyCalls);
    }

    public function testVerificationFailureFailsTheAttempt(): void
    {
        $this->store(State::INITIATED);
        $this->verification = VerificationResult::failure(VerificationResult::REASON_NOT_CONFIRMED, 'unknown');

        $outcome = $this->confirm($this->quote);

        $this->assertFailedWith(Attempt::REASON_VERIFICATION_FAILED, $outcome);
        $this->assertSame(0, $this->placementCalls);
        $this->assertNull($this->payment->getAdditionalInformation('checkout_token'));
        $this->assertSame(0, $this->paymentSaves);
        $this->assertLogged('warning', VerificationResult::REASON_NOT_CONFIRMED);
    }

    public function testAffirmOutageLeavesTheAttemptOpenForARetry(): void
    {
        $reasons = [VerificationResult::REASON_TRANSPORT_ERROR, VerificationResult::REASON_SERVICE_UNAVAILABLE];
        foreach ($reasons as $reason) {
            $this->store(State::INITIATED);
            $this->verification = VerificationResult::failure($reason);

            $outcome = $this->confirm($this->quote);

            $this->assertTrue($outcome->isInProgress(), $reason);
            $this->assertSame(ConfirmationOutcome::REASON_RETRY, $outcome->reason);
            $this->assertSame(State::INITIATED, $this->stored()->state);
            $this->assertSame([], $this->transitions);
            $this->assertSame(0, $this->placementCalls);
            $this->assertLogged('warning', $reason);
        }
    }

    public function testMalformedTokenLeavesTheAttemptOpen(): void
    {
        $this->store(State::INITIATED);
        $this->verification = VerificationResult::failure(VerificationResult::REASON_INVALID_TOKEN);

        $outcome = $this->confirm($this->quote);

        $this->assertTrue($outcome->isFailed());
        $this->assertSame(Attempt::REASON_VERIFICATION_FAILED, $outcome->reason);
        $this->assertSame(State::INITIATED, $this->stored()->state);
        $this->assertSame([], $this->transitions);
    }

    public function testConfirmationPlacesTheOrderOnce(): void
    {
        $this->store(State::INITIATED);

        $outcome = $this->confirm($this->quote);

        $this->assertTrue($outcome->isPlaced());
        $this->assertFalse($outcome->replay);
        $this->assertSame(self::ORDER_ID, $outcome->orderId);
        $this->assertSame(self::RESERVED, $outcome->incrementId);
        $this->assertSame(self::QUOTE_ID, $outcome->quoteId);
        $this->assertSame(
            ['initiated>confirmed', 'confirmed>placing', 'placing>authorizing', 'authorizing>placed'],
            $this->transitions
        );

        $stored = $this->stored();
        $this->assertSame(State::PLACED, $stored->state);
        $this->assertSame(self::ORDER_ID, $stored->orderId);
        $this->assertSame(self::RESERVED, $stored->orderIncrementId);
        $this->assertTrue($stored->hasToken(self::TOKEN));

        // Verification ran before anything was written.
        $this->assertSame(
            [['attempt_state' => State::INITIATED, 'stored_state' => State::INITIATED, 'token_on_payment' => null]],
            $this->verifyCalls
        );
        $this->assertSame(self::TOKEN, $this->payment->getAdditionalInformation('checkout_token'));
        $this->assertSame('USA', $this->payment->getAdditionalInformation('country_code'));
        $this->assertSame(self::ATTEMPT_ID, $this->payment->getAdditionalInformation('hyva_affirm_attempt_id'));
        $this->assertSame(1, $this->paymentSaves);
        $this->assertSame(1, $this->placementCalls);
        $this->assertSame([[self::ATTEMPT_ID, self::RESERVED, self::QUOTE_ID]], $this->placeCalls);

        $this->assertCount(1, $this->events);
        $this->assertSame('affirm_place_order_success', $this->events[0][0]);
        $this->assertSame($this->order, $this->events[0][1]['order']);
        $this->assertSame($this->quote, $this->events[0][1]['quote']);
        $this->assertNotContains('deactivateQuote', $this->calls);
        $this->assertNotContains('armForOrder', $this->calls);
    }

    public function testRedirectReturnLoadsTheAttemptsQuote(): void
    {
        $this->store(State::INITIATED);
        $this->cartRepository->expects($this->once())->method('get')->with(self::QUOTE_ID)->willReturn($this->quote);

        $this->assertTrue($this->confirm()->isPlaced());
    }

    public function testCanadianDollarsUseTheCanadianCountryCode(): void
    {
        $this->store(State::INITIATED, ['currency' => 'CAD']);

        $this->assertTrue($this->confirm($this->quote)->isPlaced());
        $this->assertSame('CAN', $this->payment->getAdditionalInformation('country_code'));
    }

    public function testConfirmedAttemptWithTheSameTokenIsPlaced(): void
    {
        $this->store(State::CONFIRMED, ['token_hash' => Attempt::hashToken(self::TOKEN)]);

        $outcome = $this->confirm($this->quote);

        $this->assertTrue($outcome->isPlaced());
        $this->assertSame(['confirmed>placing', 'placing>authorizing', 'authorizing>placed'], $this->transitions);
    }

    public function testConfirmedAttemptWithAnotherTokenIsLeftToItsRequest(): void
    {
        $this->store(State::CONFIRMED, ['token_hash' => Attempt::hashToken(self::OTHER_TOKEN)]);

        $outcome = $this->confirm($this->quote);

        $this->assertTrue($outcome->isInProgress());
        $this->assertSame(0, $this->placementCalls);
        $this->assertSame([], $this->transitions);
    }

    public function testLostConfirmationRaceIsInProgress(): void
    {
        $this->store(State::INITIATED);
        $this->race = function (string $attemptId, State $to): void {
            if ($to === State::CONFIRMED) {
                $this->overwrite(State::PLACING, ['token_hash' => Attempt::hashToken(self::OTHER_TOKEN)]);
            }
        };

        $outcome = $this->confirm($this->quote);

        $this->assertTrue($outcome->isInProgress());
        $this->assertSame(0, $this->placementCalls);
        $this->assertNull($this->payment->getAdditionalInformation('checkout_token'));
    }

    public function testLostClaimToAFinishedPlacementReplaysIt(): void
    {
        $this->store(State::INITIATED);
        $this->race = function (string $attemptId, State $to): void {
            if ($to === State::PLACING) {
                $this->overwrite(State::PLACED, ['order_id' => self::ORDER_ID, 'order_increment_id' => self::RESERVED]);
            }
        };

        $outcome = $this->confirm($this->quote);

        $this->assertTrue($outcome->isPlaced());
        $this->assertTrue($outcome->replay);
        $this->assertSame(0, $this->placementCalls);
        $this->assertSame([], $this->events);
    }

    public function testAnotherUnresolvedAttemptOfTheCartBlocksPlacement(): void
    {
        $this->store(State::INITIATED);
        $other = $this->attempt(State::UNCERTAIN, ['attempt_id' => self::OTHER_ATTEMPT_ID]);
        $this->rows[self::OTHER_ATTEMPT_ID] = $other;

        $outcome = $this->confirm($this->quote);

        $this->assertTrue($outcome->isFailed());
        $this->assertSame(ConfirmationOutcome::REASON_IN_PROGRESS, $outcome->reason);
        $this->assertSame(0, $this->placementCalls);
        $this->assertSame(State::CONFIRMED, $this->stored()->state);
        $this->assertTrue($this->stored()->hasToken(self::TOKEN));
    }

    public function testCartLockHandsTheAttemptBackForARetry(): void
    {
        $this->store(State::INITIATED);
        $this->placement = fn (): int => throw new CartLockedException(__('The cart is locked for processing.'));

        $outcome = $this->confirm($this->quote);

        $this->assertTrue($outcome->isInProgress());
        $this->assertSame(ConfirmationOutcome::REASON_RETRY, $outcome->reason);
        $this->assertSame(State::CONFIRMED, $this->stored()->state);
        $this->assertTrue($this->stored()->hasToken(self::TOKEN));
        $this->assertSame(self::TOKEN, $this->payment->getAdditionalInformation('checkout_token'));
        $this->assertNotContains('findOrder', $this->calls);

        // The retry with the same token completes.
        $this->placement = fn (): int => self::ORDER_ID;
        $this->assertTrue($this->confirm($this->quote)->isPlaced());
        $this->assertSame(State::PLACED, $this->stored()->state);
    }

    public function testExceptionBeforeTheAuthorizationFailsTheAttempt(): void
    {
        $this->store(State::INITIATED);
        $this->placement = fn (): int => throw new LocalizedException(
            __('Could not acquire inventory lock for %1', self::TOKEN)
        );

        $outcome = $this->confirm($this->quote);

        $this->assertFailedWith(Attempt::REASON_PLACEMENT_FAILED, $outcome);
        $this->assertNull($this->payment->getAdditionalInformation('checkout_token'));
        $this->assertNull($this->payment->getAdditionalInformation('hyva_affirm_attempt_id'));
        $this->assertSame(2, $this->paymentSaves);
        $this->assertSame([], $this->notifications);
        $this->assertSame([], $this->events);
        // The exception message is logged without the token.
        $this->assertLogged('error', '[redacted]');
    }

    public function testOrderFoundAfterAnExceptionIsCompleted(): void
    {
        foreach ([State::PLACING, State::AUTHORIZING] as $stateAtException) {
            $this->resetCart();
            $this->store(State::INITIATED);
            $this->placement = function () use ($stateAtException): int {
                if ($stateAtException === State::AUTHORIZING) {
                    $this->repository->transition(self::ATTEMPT_ID, [State::PLACING], State::AUTHORIZING);
                }
                throw new RuntimeException('sales_model_service_quote_submit_success observer failed');
            };
            $this->orderLookup = fn (): OrderInterface => $this->order;

            $outcome = $this->confirm($this->quote);

            $this->assertTrue($outcome->isPlaced(), $stateAtException->value);
            $this->assertFalse($outcome->replay);
            $this->assertSame(self::ORDER_ID, $outcome->orderId);
            $this->assertSame(State::PLACED, $this->stored()->state);
            $this->assertSame(['findOrder', 'deactivateQuote', 'armForOrder'], $this->callsWithout('collectTotals'));
            $this->assertCount(1, $this->events);
            $this->assertSame([], $this->notifications);
            $this->assertLogged('warning', 'checkout_submit_all_after');
        }
    }

    /**
     * @return array<string, array{0: array{quote_id?: int, method?: string, attempt_id?: string|null}}>
     */
    public static function foreignOrders(): array
    {
        return [
            'another cart' => [['quote_id' => 43]],
            'another payment method' => [['method' => 'braintree']],
            'another attempt' => [['attempt_id' => str_repeat('b', 64)]],
            'no attempt id' => [['attempt_id' => null]],
        ];
    }

    /**
     * @param array{quote_id?: int, method?: string, attempt_id?: string|null} $origin
     */
    #[DataProvider('foreignOrders')]
    public function testForeignOrderWithTheReservedNumberIsNotTakenAfterTheAuthorization(array $origin): void
    {
        $this->store(State::INITIATED);
        $this->placement = function (): int {
            $this->repository->transition(self::ATTEMPT_ID, [State::PLACING], State::AUTHORIZING);
            throw new RuntimeException('order save failed');
        };
        $this->orderLookup = fn (): OrderInterface => $this->order;
        $this->orderOrigin = array_merge($this->orderOrigin, $origin);

        $outcome = $this->confirm($this->quote);

        $this->assertTrue($outcome->isUncertain());
        $this->assertSame(State::UNCERTAIN, $this->stored()->state);
        $this->assertNull($this->stored()->orderId);
        $this->assertSame([self::RESERVED], $this->notifications);
        $this->assertSame([], $this->events);
        $this->assertNotContains('deactivateQuote', $this->calls);
        $this->assertLogged('warning', 'not placed for this checkout attempt');
        $this->assertLogged('critical', self::RESERVED);
    }

    /**
     * @param array{quote_id?: int, method?: string, attempt_id?: string|null} $origin
     */
    #[DataProvider('foreignOrders')]
    public function testForeignOrderWithTheReservedNumberIsNotTakenBeforeTheAuthorization(array $origin): void
    {
        $this->store(State::INITIATED);
        // For example the submit guard refused a re-reserved order number: nothing reached Affirm.
        $this->placement = fn (): int => throw new LocalizedException(__('The order number is no longer available.'));
        $this->orderLookup = fn (): OrderInterface => $this->order;
        $this->orderOrigin = array_merge($this->orderOrigin, $origin);

        $outcome = $this->confirm($this->quote);

        $this->assertFailedWith(Attempt::REASON_PLACEMENT_FAILED, $outcome);
        $this->assertNull($this->stored()->orderId);
        $this->assertSame([], $this->events);
        $this->assertSame([], $this->notifications);
        $this->assertNull($this->payment->getAdditionalInformation('checkout_token'));
        $this->assertLogged('warning', self::RESERVED);
    }

    public function testExceptionAfterTheAuthorizationWithoutOrderIsUncertain(): void
    {
        $this->store(State::INITIATED);
        $this->placement = function (): int {
            $this->repository->transition(self::ATTEMPT_ID, [State::PLACING], State::AUTHORIZING);
            throw new RuntimeException('Capture failed for ' . self::TOKEN);
        };

        $outcome = $this->confirm($this->quote);

        $this->assertTrue($outcome->isUncertain());
        $this->assertSame(self::RESERVED, $outcome->incrementId);
        $this->assertStringContainsString(self::RESERVED, $outcome->message);
        $this->assertSame(State::UNCERTAIN, $this->stored()->state);
        $this->assertSame([self::RESERVED], $this->notifications);
        $this->assertSame(self::TOKEN, $this->payment->getAdditionalInformation('checkout_token'));
        $this->assertSame([], $this->events);
        $this->assertLogged('critical', self::RESERVED);
    }

    public function testUnknownPlacementResultIsNeverReportedAsNoPayment(): void
    {
        $this->store(State::INITIATED);
        $this->placement = function (): int {
            $this->repository->transition(self::ATTEMPT_ID, [State::PLACING], State::AUTHORIZING);
            throw new RuntimeException('lost response');
        };
        $this->orderLookup = fn (): ?OrderInterface => throw new RuntimeException('database gone');

        $outcome = $this->confirm($this->quote);

        $this->assertTrue($outcome->isUncertain());
        $this->assertSame(State::AUTHORIZING, $this->stored()->state);
    }

    public function testPlacementWithoutOrderIdIsTreatedAsAnException(): void
    {
        $this->store(State::INITIATED);
        $this->placement = function (): int {
            $this->repository->transition(self::ATTEMPT_ID, [State::PLACING], State::AUTHORIZING);

            return 0;
        };

        $this->assertTrue($this->confirm($this->quote)->isUncertain());
        $this->assertSame(State::UNCERTAIN, $this->stored()->state);
    }

    public function testFailingSuccessObserverDoesNotChangeTheOutcome(): void
    {
        $this->store(State::INITIATED);
        $this->observer = fn () => throw new RuntimeException('observer');

        $outcome = $this->confirm($this->quote);

        $this->assertTrue($outcome->isPlaced());
        $this->assertSame(State::PLACED, $this->stored()->state);
        $this->assertLogged('error', 'affirm_place_order_success');
    }

    public function testUnexpectedErrorBeforeTheClaimLeavesTheAttemptUnchanged(): void
    {
        $this->store(State::INITIATED);
        $this->collectTotals = fn () => throw new RuntimeException('tax service down ' . self::TOKEN);

        $outcome = $this->confirm($this->quote);

        $this->assertTrue($outcome->isFailed());
        $this->assertSame(ConfirmationOutcome::REASON_RETRY, $outcome->reason);
        $this->assertSame(State::INITIATED, $this->stored()->state);
        $this->assertSame([], $this->transitions);
    }

    /**
     * Run the pipeline and remember the outcome for the leak check.
     *
     * @param Quote|null $quote
     * @return ConfirmationOutcome
     */
    private function confirm(?Quote $quote = null): ConfirmationOutcome
    {
        $outcome = $this->pipeline->confirm(self::ATTEMPT_ID, self::TOKEN, $quote);
        $this->outcomes[] = $outcome;

        return $outcome;
    }

    /**
     * Assert a failed outcome and a failed attempt with the reason.
     *
     * @param string $reason
     * @param ConfirmationOutcome $outcome
     * @param string $case
     * @return void
     */
    private function assertFailedWith(string $reason, ConfirmationOutcome $outcome, string $case = ''): void
    {
        $this->assertTrue($outcome->isFailed(), $case);
        $this->assertSame($reason, $outcome->reason, $case);
        $this->assertNotSame('', $outcome->message, $case);
        $this->assertSame(State::FAILED, $this->stored()->state, $case);
        $this->assertSame($reason, $this->stored()->failureReason, $case);
    }

    /**
     * Assert that a log line of the level mentions a text in its message or context.
     *
     * @param string $level
     * @param string $text
     * @return void
     */
    private function assertLogged(string $level, string $text): void
    {
        foreach ($this->logs as [$logged, $message, $context]) {
            if ($logged === $level && str_contains($message . json_encode($context), $text)) {
                $this->addToAssertionCount(1);

                return;
            }
        }
        $this->fail(sprintf('No %s log line mentions "%s".', $level, $text));
    }

    /**
     * Recorded calls without the given prefix.
     *
     * @param string $prefix
     * @return list<string>
     */
    private function callsWithout(string $prefix): array
    {
        return array_values(array_filter($this->calls, fn (string $call): bool => !str_starts_with($call, $prefix)));
    }

    /**
     * Reset the cart and the recorded calls between cases of one test.
     *
     * @return void
     */
    private function resetCart(): void
    {
        $this->quoteId = self::QUOTE_ID;
        $this->quoteActive = true;
        $this->quoteStoreId = self::STORE_ID;
        $this->currentStoreId = self::STORE_ID;
        $this->paymentMethod = 'affirm_gateway';
        $this->payment->setData('additional_information', []);
        $this->payment->unsAdditionalInformation();
        $this->calls = [];
        $this->events = [];
        $this->transitions = [];
        $this->placementCalls = 0;
    }

    /**
     * Store the attempt under test.
     *
     * @param State $state
     * @param array<string, mixed> $overrides
     * @return void
     */
    private function store(State $state, array $overrides = []): void
    {
        $this->rows[self::ATTEMPT_ID] = $this->attempt($state, $overrides);
    }

    /**
     * Replace the stored attempt as a concurrent request would (without recording a transition).
     *
     * @param State $state
     * @param array<string, mixed> $overrides
     * @return void
     */
    private function overwrite(State $state, array $overrides): void
    {
        $this->rows[self::ATTEMPT_ID] = Attempt::fromRow(
            array_merge($this->stored()->toArray(), $overrides, ['state' => $state->value])
        );
    }

    /**
     * The stored attempt under test.
     *
     * @return Attempt
     */
    private function stored(): Attempt
    {
        return $this->rows[self::ATTEMPT_ID];
    }

    /**
     * An attempt row.
     *
     * @param State $state
     * @param array<string, mixed> $overrides
     * @return Attempt
     */
    private function attempt(State $state, array $overrides = []): Attempt
    {
        return Attempt::fromRow(array_merge([
            'attempt_id' => self::ATTEMPT_ID,
            'owner_key_hash' => hash('sha256', 'owner'),
            'quote_id' => self::QUOTE_ID,
            'store_id' => self::STORE_ID,
            'customer_id' => null,
            'reserved_increment_id' => self::RESERVED,
            'currency' => 'USD',
            'grand_total_cents' => 123456,
            'payment_method' => 'affirm_gateway',
            'snapshot_hash' => $this->snapshotHash(),
            'state' => $state->value,
            'token_hash' => null,
            'order_increment_id' => null,
            'order_id' => null,
            'failure_reason' => null,
            'provider_transaction_id' => null,
            'created_at' => gmdate('Y-m-d H:i:s', self::NOW - 60),
            'updated_at' => gmdate('Y-m-d H:i:s', self::NOW - 60),
            'expires_at' => gmdate('Y-m-d H:i:s', self::NOW + 3600),
        ], $overrides));
    }

    /**
     * The snapshot hash stored on the attempt.
     *
     * @return string
     */
    private function snapshotHash(): string
    {
        return hash('sha256', 'cart');
    }

    /**
     * In-memory attempt storage with the repository's compare-and-swap contract.
     *
     * @return AttemptRepositoryInterface
     */
    private function memoryRepository(): AttemptRepositoryInterface
    {
        $onTransition = function (string $attemptId, State $from, State $to, bool $before): void {
            if ($before) {
                if ($this->race !== null) {
                    ($this->race)($attemptId, $to);
                }

                return;
            }
            $this->transitions[] = $from->value . '>' . $to->value;
        };

        $rows = fn (): array => $this->rows;
        $save = function (Attempt $attempt): void {
            $this->rows[$attempt->attemptId] = $attempt;
        };

        return new class($rows, $save, $onTransition) implements AttemptRepositoryInterface {
            /**
             * @param Closure(): array<string, Attempt> $rows
             * @param Closure(Attempt): void $save
             * @param Closure(string, State, State, bool): void $onTransition
             */
            public function __construct(
                private readonly Closure $rows,
                private readonly Closure $save,
                private readonly Closure $onTransition
            ) {
            }

            public function create(
                int $quoteId,
                int $storeId,
                ?int $customerId,
                string $reservedIncrementId,
                string $currency,
                int $grandTotalCents,
                string $snapshotHash,
                string $ownerKeyHash,
                string $paymentMethod = 'affirm_gateway'
            ): Attempt {
                throw new LogicException('Not used by the pipeline.');
            }

            public function get(string $attemptId): ?Attempt
            {
                return ($this->rows)()[$attemptId] ?? null;
            }

            public function supersedeOthersForQuote(int $quoteId, string $keepAttemptId): int
            {
                throw new LogicException('Not used by the pipeline.');
            }

            public function findReusable(int $quoteId, string $ownerKeyHash, string $snapshotHash): ?Attempt
            {
                throw new LogicException('Not used by the pipeline.');
            }

            public function countRecentForQuote(int $quoteId, DateTimeInterface $since): int
            {
                throw new LogicException('Not used by the pipeline.');
            }

            public function transition(
                string $attemptId,
                array $fromStates,
                State|string $toState,
                array $set = [],
                array $conditions = []
            ): bool {
                $to = $toState instanceof State ? $toState : State::from($toState);
                $from = [];
                foreach ($fromStates as $state) {
                    $state = $state instanceof State ? $state : State::from($state);
                    if (!$state->canTransitionTo($to)) {
                        throw new InvalidArgumentException('Transition not allowed.');
                    }
                    $from[] = $state;
                }
                ($this->onTransition)($attemptId, $to, $to, true);

                $row = ($this->rows)()[$attemptId] ?? null;
                if ($row === null || !in_array($row->state, $from, true)) {
                    return false;
                }
                $data = $row->toArray();
                foreach ($conditions as $column => $value) {
                    if ($data[$column] !== $value) {
                        return false;
                    }
                }
                ($this->save)(Attempt::fromRow(array_merge($data, $set, ['state' => $to->value])));
                ($this->onTransition)($attemptId, $row->state, $to, false);

                return true;
            }

            public function recordTransactionId(string $attemptId, string $transactionId): bool
            {
                return false;
            }

            public function hasUnresolvedForQuote(int $quoteId, ?string $exceptAttemptId = null): bool
            {
                foreach (($this->rows)() as $row) {
                    if ($row->quoteId === $quoteId && $row->state->isUnresolved()
                        && $row->attemptId !== $exceptAttemptId
                    ) {
                        return true;
                    }
                }

                return false;
            }

            public function listUnresolved(int $limit = 200): array
            {
                return [];
            }

            public function prune(int $olderThanDays): int
            {
                return 0;
            }
        };
    }
}
