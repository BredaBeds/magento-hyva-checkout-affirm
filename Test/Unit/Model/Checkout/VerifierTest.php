<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Test\Unit\Model\Checkout;

use Hyva\CheckoutAffirm\Model\Attempt\Attempt;
use Hyva\CheckoutAffirm\Model\Attempt\State;
use Hyva\CheckoutAffirm\Model\Checkout\VerificationResult;
use Hyva\CheckoutAffirm\Model\Checkout\Verifier;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\HTTP\ClientFactory;
use Magento\Framework\HTTP\ClientInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class VerifierTest extends TestCase
{
    private const TOKEN = 'MARKERTOKEN0001';

    private const STORE_ID = 3;

    /**
     * @var array<string, string|null>
     */
    private array $config = [];

    /**
     * @var ClientInterface&MockObject
     */
    private ClientInterface $client;

    /**
     * @var ClientFactory&MockObject
     */
    private ClientFactory $clientFactory;

    /**
     * @var EncryptorInterface&MockObject
     */
    private EncryptorInterface $encryptor;

    /**
     * @var list<array{0: string, 1: array<string, mixed>}>
     */
    private array $warnings = [];

    /**
     * @var array<string, string>
     */
    private array $headers = [];

    /**
     * @var string|null
     */
    private ?string $requestedUrl = null;

    /**
     * @var Verifier
     */
    private Verifier $verifier;

    protected function setUp(): void
    {
        $this->config = [
            'payment/affirm_gateway/mode' => 'sandbox',
            'payment/affirm_gateway/public_api_key_sandbox' => 'PUBSANDBOX',
            'payment/affirm_gateway/private_api_key_sandbox' => 'PRIVSANDBOX',
            'payment/affirm_gateway/public_api_key_production' => 'PUBPROD',
            'payment/affirm_gateway/private_api_key_production' => 'PRIVPROD',
            'payment/affirm_gateway/public_api_key_sandbox_ca' => 'PUBSANDBOXCA',
            'payment/affirm_gateway/private_api_key_sandbox_ca' => 'PRIVSANDBOXCA',
        ];
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            function (string $path, string $scope, $storeId) {
                $this->assertSame('store', $scope);
                $this->assertSame(self::STORE_ID, $storeId);
                return $this->config[$path] ?? null;
            }
        );

        $this->client = $this->createMock(ClientInterface::class);
        $this->client->method('addHeader')->willReturnCallback(function (string $name, string $value): void {
            $this->headers[$name] = $value;
        });
        $this->client->method('get')->willReturnCallback(function (string $url): void {
            $this->requestedUrl = $url;
        });
        $this->clientFactory = $this->createMock(ClientFactory::class);
        $this->clientFactory->method('create')->willReturn($this->client);

        $this->encryptor = $this->createMock(EncryptorInterface::class);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function ($message, array $context = []): void {
            $this->warnings[] = [(string) $message, $context];
        });

        $this->verifier = new Verifier($scopeConfig, $this->encryptor, $this->clientFactory, $logger);
    }

    protected function tearDown(): void
    {
        // No log line may ever carry the token.
        $this->assertStringNotContainsString(self::TOKEN, var_export($this->warnings, true));
    }

    public function testConfirmedCheckoutPasses(): void
    {
        $this->client->expects($this->once())->method('setTimeout')->with(10);
        $this->client->expects($this->once())->method('setCredentials')->with('PUBSANDBOX', 'PRIVSANDBOX');
        $this->respond(200, $this->response());

        $result = $this->verifier->verify($this->attempt(), self::TOKEN);

        $this->assertTrue($result->ok);
        $this->assertSame('', $result->reason);
        $this->assertSame('confirmed', $result->checkoutStatus);
        $this->assertSame('000000123', $result->orderId);
        $this->assertSame(120000, $result->total);
        $this->assertSame('USD', $result->currency);
        $this->assertSame('https://api.global.sandbox.affirm.com/api/v2/checkout/' . self::TOKEN, $this->requestedUrl);
        $this->assertSame(['Accept' => 'application/json', 'Country-Code' => 'USA'], $this->headers);
        $this->assertSame([], $this->warnings);
    }

    public function testProductionModeUsesProductionHostAndKeys(): void
    {
        $this->config['payment/affirm_gateway/mode'] = 'production';
        $this->client->expects($this->once())->method('setCredentials')->with('PUBPROD', 'PRIVPROD');
        $this->respond(200, $this->response());

        $this->assertTrue($this->verifier->verify($this->attempt(), self::TOKEN)->ok);
        $this->assertSame('https://api.global.affirm.com/api/v2/checkout/' . self::TOKEN, $this->requestedUrl);
    }

    public function testCanadianDollarsUseCanadianKeys(): void
    {
        $this->client->expects($this->once())->method('setCredentials')->with('PUBSANDBOXCA', 'PRIVSANDBOXCA');
        $this->respond(200, $this->response(['currency' => 'CAD']));

        $this->assertTrue($this->verifier->verify($this->attempt('CAD'), self::TOKEN)->ok);
        $this->assertSame('CAN', $this->headers['Country-Code']);
    }

    public function testEncryptedPrivateKeyIsDecrypted(): void
    {
        $this->config['payment/affirm_gateway/private_api_key_sandbox'] = '0:3:c2VjcmV0';
        $this->encryptor->expects($this->once())->method('decrypt')->with('0:3:c2VjcmV0')->willReturn('PRIVPLAIN');
        $this->client->expects($this->once())->method('setCredentials')->with('PUBSANDBOX', 'PRIVPLAIN');
        $this->respond(200, $this->response());

        $this->assertTrue($this->verifier->verify($this->attempt(), self::TOKEN)->ok);
    }

    public function testPlainPrivateKeyIsNotDecryptedAgain(): void
    {
        $this->encryptor->expects($this->never())->method('decrypt');
        $this->respond(200, $this->response());

        $this->assertTrue($this->verifier->verify($this->attempt(), self::TOKEN)->ok);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function badResponses(): array
    {
        return [
            'missing checkout_status' => [['checkout_status' => null], VerificationResult::REASON_MISSING_FIELD],
            'missing order_id' => [['order_id' => null], VerificationResult::REASON_MISSING_FIELD],
            'empty order_id' => [['order_id' => ''], VerificationResult::REASON_MISSING_FIELD],
            'missing total' => [['total' => null], VerificationResult::REASON_MISSING_FIELD],
            'missing currency' => [['currency' => null], VerificationResult::REASON_MISSING_FIELD],
            'fractional total' => [['total' => 120000.5], VerificationResult::REASON_MISSING_FIELD],
            'text total' => [['total' => '1200.00'], VerificationResult::REASON_MISSING_FIELD],
            'array total' => [['total' => [120000]], VerificationResult::REASON_MISSING_FIELD],
            'unconfirmed' => [['checkout_status' => 'unknown'], VerificationResult::REASON_NOT_CONFIRMED],
            'status case' => [['checkout_status' => 'Confirmed'], VerificationResult::REASON_NOT_CONFIRMED],
            'other order' => [['order_id' => '000000124'], VerificationResult::REASON_ORDER_ID_MISMATCH],
            'order without zeros' => [['order_id' => 123], VerificationResult::REASON_ORDER_ID_MISMATCH],
            'lower total' => [['total' => 119999], VerificationResult::REASON_TOTAL_MISMATCH],
            'higher total' => [['total' => 120001], VerificationResult::REASON_TOTAL_MISMATCH],
            'other currency' => [['currency' => 'CAD'], VerificationResult::REASON_CURRENCY_MISMATCH],
        ];
    }

    /**
     * @param array<string, mixed> $override
     */
    #[DataProvider('badResponses')]
    public function testUnexpectedResponseFailsClosed(array $override, string $reason): void
    {
        $this->respond(200, $this->response($override));

        $result = $this->verifier->verify($this->attempt(), self::TOKEN);

        $this->assertFalse($result->ok);
        $this->assertSame($reason, $result->reason);
        $this->assertSame(
            [['Affirm checkout verification failed.', ['reason' => $reason, 'http_status' => 200]]],
            $this->warnings
        );
    }

    public function testEquivalentRepresentationsPass(): void
    {
        $this->respond(200, $this->response(['total' => '120000', 'currency' => 'usd']));

        $this->assertTrue($this->verifier->verify($this->attempt(), self::TOKEN)->ok);
    }

    public function testObservedValuesAreReported(): void
    {
        $this->respond(200, $this->response(['total' => 5000]));

        $result = $this->verifier->verify($this->attempt(), self::TOKEN);

        $this->assertSame(5000, $result->total);
        $this->assertSame('000000123', $result->orderId);
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function httpErrors(): array
    {
        return ['bad request' => [400], 'unauthorized' => [401], 'not found' => [404], 'redirect' => [302],
            'no status' => [0], 'created' => [201]];
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function unavailableStatuses(): array
    {
        return ['server error' => [500], 'bad gateway' => [502], 'unavailable' => [503], 'rate limited' => [429]];
    }

    #[DataProvider('unavailableStatuses')]
    public function testServerErrorsAndRateLimitsAreTemporary(int $status): void
    {
        $this->respond($status, $this->response());

        $result = $this->verifier->verify($this->attempt(), self::TOKEN);

        $this->assertFalse($result->ok);
        $this->assertSame(VerificationResult::REASON_SERVICE_UNAVAILABLE, $result->reason);
        $this->assertTrue($result->isTemporary());
        $this->assertSame(
            [['Affirm checkout verification failed.', ['reason' => 'service_unavailable', 'http_status' => $status]]],
            $this->warnings
        );
    }

    #[DataProvider('httpErrors')]
    public function testHttpErrorsFailClosedAndLogOnlyTheStatus(int $status): void
    {
        $this->respond($status, $this->response());

        $result = $this->verifier->verify($this->attempt(), self::TOKEN);

        $this->assertFalse($result->ok);
        $this->assertSame(VerificationResult::REASON_HTTP_ERROR, $result->reason);
        $this->assertFalse($result->isTemporary());
        $this->assertNull($result->checkoutStatus);
        $this->assertSame(
            [['Affirm checkout verification failed.', ['reason' => 'http_error', 'http_status' => $status]]],
            $this->warnings
        );
    }

    public function testTimeoutFailsClosed(): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->method('get')->willThrowException(
            new \Exception('Operation timed out after 10001 milliseconds for /api/v2/checkout/' . self::TOKEN)
        );
        $factory = $this->createMock(ClientFactory::class);
        $factory->method('create')->willReturn($client);
        $verifier = $this->verifierWith($factory);

        $result = $verifier->verify($this->attempt(), self::TOKEN);

        $this->assertFalse($result->ok);
        $this->assertSame(VerificationResult::REASON_TRANSPORT_ERROR, $result->reason);
        $this->assertTrue($result->isTemporary());
        $this->assertSame(
            [['Affirm checkout verification failed.', ['reason' => 'transport_error', 'http_status' => null]]],
            $this->warnings
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unreadableBodies(): array
    {
        return ['empty' => [''], 'html' => ['<html>busy</html>'], 'scalar' => ['"confirmed"'], 'number' => ['1']];
    }

    #[DataProvider('unreadableBodies')]
    public function testUnreadableBodyFailsClosed(string $body): void
    {
        $this->client->method('getStatus')->willReturn(200);
        $this->client->method('getBody')->willReturn($body);

        $result = $this->verifier->verify($this->attempt(), self::TOKEN);

        $this->assertFalse($result->ok);
        $this->assertSame(VerificationResult::REASON_INVALID_RESPONSE, $result->reason);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidTokens(): array
    {
        return ['empty' => [''], 'path' => ['../transactions'], 'query' => ['abc?x=1'], 'newline' => ["abc\n"],
            'too long' => [str_repeat('a', 129)]];
    }

    #[DataProvider('invalidTokens')]
    public function testMalformedTokenIsRejectedWithoutRequest(string $token): void
    {
        $this->clientFactory->expects($this->never())->method('create');

        $result = $this->verifier->verify($this->attempt(), $token);

        $this->assertFalse($result->ok);
        $this->assertSame(VerificationResult::REASON_INVALID_TOKEN, $result->reason);
    }

    public function testUnsupportedCurrencyIsRejectedWithoutRequest(): void
    {
        $this->clientFactory->expects($this->never())->method('create');

        $result = $this->verifier->verify($this->attempt('EUR'), self::TOKEN);

        $this->assertSame(VerificationResult::REASON_UNSUPPORTED_CURRENCY, $result->reason);
    }

    public function testMissingKeysAreRejectedWithoutRequest(): void
    {
        $this->config['payment/affirm_gateway/private_api_key_sandbox'] = '  ';
        $this->clientFactory->expects($this->never())->method('create');

        $result = $this->verifier->verify($this->attempt(), self::TOKEN);

        $this->assertSame(VerificationResult::REASON_NOT_CONFIGURED, $result->reason);
    }

    public function testMissingCanadianKeysAreNotReplacedByUsKeys(): void
    {
        unset($this->config['payment/affirm_gateway/public_api_key_sandbox_ca']);
        $this->clientFactory->expects($this->never())->method('create');

        $result = $this->verifier->verify($this->attempt('CAD'), self::TOKEN);

        $this->assertSame(VerificationResult::REASON_NOT_CONFIGURED, $result->reason);
    }

    private function verifierWith(ClientFactory $factory): Verifier
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(fn (string $path) => $this->config[$path] ?? null);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function ($message, array $context = []): void {
            $this->warnings[] = [(string) $message, $context];
        });

        return new Verifier($scopeConfig, $this->encryptor, $factory, $logger);
    }

    private function respond(int $status, string $body): void
    {
        $this->client->method('getStatus')->willReturn($status);
        $this->client->method('getBody')->willReturn($body);
    }

    /**
     * A Read Checkout response in the shape of Affirm's sandbox fixture.
     *
     * @param array<string, mixed> $override
     */
    private function response(array $override = []): string
    {
        $data = array_merge([
            'checkout_status' => 'confirmed',
            'order_id' => '000000123',
            'merchant_external_reference' => '000000123',
            'total' => 120000,
            'currency' => 'USD',
            'api_version' => 'v2',
            'tax_amount' => 0,
            'shipping_amount' => 0,
            'items' => ['BED-Q' => ['sku' => 'BED-Q', 'unit_price' => 120000, 'qty' => 1]],
            'metadata' => ['platform_integration' => 'hyva-checkout-affirm/0.1.0'],
        ], $override);

        return json_encode(array_filter($data, static fn ($value): bool => $value !== null), JSON_THROW_ON_ERROR);
    }

    private function attempt(string $currency = 'USD'): Attempt
    {
        return new Attempt(
            attemptId: str_repeat('ab', 32),
            ownerKeyHash: str_repeat('1', 64),
            quoteId: 42,
            storeId: self::STORE_ID,
            customerId: null,
            reservedIncrementId: '000000123',
            currency: $currency,
            grandTotalCents: 120000,
            paymentMethod: 'affirm_gateway',
            snapshotHash: str_repeat('2', 64),
            state: State::INITIATED,
            tokenHash: null,
            orderIncrementId: null,
            orderId: null,
            failureReason: null,
            providerTransactionId: null,
            createdAt: '2026-10-07 10:00:00',
            updatedAt: '2026-10-07 10:00:00',
            expiresAt: '2026-10-07 11:00:00'
        );
    }
}
