<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Model\Checkout;

use Astound\Affirm\Gateway\Helper\Request\Action;
use Astound\Affirm\Model\Config as AffirmConfig;
use Astound\Affirm\Model\Credential;
use Hyva\CheckoutAffirm\Model\Attempt\Attempt;
use JsonException;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\HTTP\ClientFactory;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

/**
 * Affirm Read Checkout (GET /api/v2/checkout/{token}) with its own HTTP client.
 *
 * Keys and mode are read for the attempt's store and currency explicitly: Affirm's own getters use the
 * current store and its current currency, and its shared gateway client keeps headers between calls.
 */
class Verifier implements VerifierInterface
{
    private const TIMEOUT_SECONDS = 10;

    private const CONFIG_PATH = 'payment/affirm_gateway/';

    private const TOKEN_PATTERN = '/^[A-Za-z0-9_-]{1,128}\z/';

    /**
     * Magento's encrypted config value format ("<key version>:<cipher version>:<payload>").
     */
    private const ENCRYPTED_PATTERN = '/^\d+:\d+:\S+\z/';

    private const COUNTRY_BY_CURRENCY = [
        AffirmConfig::CURRENCY_CODE_USD => AffirmConfig::COUNTRY_CODE_USA,
        AffirmConfig::CURRENCY_CODE_CAD => AffirmConfig::COUNTRY_CODE_CAN,
    ];

    private const STATUS_CONFIRMED = 'confirmed';

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param EncryptorInterface $encryptor
     * @param ClientFactory $clientFactory
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
        private readonly ClientFactory $clientFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritdoc
     */
    public function verify(Attempt $attempt, #[\SensitiveParameter] string $checkoutToken): VerificationResult
    {
        if (preg_match(self::TOKEN_PATTERN, $checkoutToken) !== 1) {
            return $this->fail(VerificationResult::REASON_INVALID_TOKEN);
        }
        $currency = strtoupper($attempt->currency);
        $country = self::COUNTRY_BY_CURRENCY[$currency] ?? null;
        if ($country === null) {
            return $this->fail(VerificationResult::REASON_UNSUPPORTED_CURRENCY);
        }

        try {
            [$baseUrl, $publicKey, $privateKey] = $this->credentials($attempt->storeId, $country);
        } catch (\Throwable) {
            $publicKey = $privateKey = $baseUrl = '';
        }
        if ($publicKey === '' || $privateKey === '') {
            return $this->fail(VerificationResult::REASON_NOT_CONFIGURED);
        }

        try {
            $client = $this->clientFactory->create();
            $client->setTimeout(self::TIMEOUT_SECONDS);
            $client->setCredentials($publicKey, $privateKey);
            $client->addHeader('Accept', 'application/json');
            $client->addHeader('Country-Code', $country);
            $client->get($baseUrl . Action::API_CHECKOUT_PATH . rawurlencode($checkoutToken));
            $status = (int) $client->getStatus();
            $body = (string) $client->getBody();
        } catch (\Throwable) {
            // Transport errors (timeouts, DNS, TLS) carry no detail worth the risk of logging request data.
            return $this->fail(VerificationResult::REASON_TRANSPORT_ERROR);
        }

        if ($status !== 200) {
            return $this->fail(VerificationResult::REASON_HTTP_ERROR, $status);
        }

        return $this->evaluate($attempt, $currency, $body);
    }

    /**
     * Compare the Read Checkout response with the attempt.
     *
     * @param Attempt $attempt
     * @param string $currency
     * @param string $body
     * @return VerificationResult
     */
    private function evaluate(Attempt $attempt, string $currency, string $body): VerificationResult
    {
        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->fail(VerificationResult::REASON_INVALID_RESPONSE, 200);
        }
        if (!is_array($data)) {
            return $this->fail(VerificationResult::REASON_INVALID_RESPONSE, 200);
        }

        $checkoutStatus = is_string($data['checkout_status'] ?? null) ? $data['checkout_status'] : null;
        $orderId = $this->readOrderId($data['order_id'] ?? null);
        $total = $this->readCents($data['total'] ?? null);
        $observedCurrency = is_string($data['currency'] ?? null) && $data['currency'] !== ''
            ? strtoupper($data['currency'])
            : null;

        if ($checkoutStatus === null || $orderId === null || $total === null || $observedCurrency === null) {
            return $this->fail(
                VerificationResult::REASON_MISSING_FIELD,
                200,
                $checkoutStatus,
                $orderId,
                $total,
                $observedCurrency
            );
        }

        $reason = match (true) {
            $checkoutStatus !== self::STATUS_CONFIRMED => VerificationResult::REASON_NOT_CONFIRMED,
            $orderId !== $attempt->reservedIncrementId => VerificationResult::REASON_ORDER_ID_MISMATCH,
            $total !== $attempt->grandTotalCents => VerificationResult::REASON_TOTAL_MISMATCH,
            $observedCurrency !== $currency => VerificationResult::REASON_CURRENCY_MISMATCH,
            default => null,
        };
        if ($reason !== null) {
            return $this->fail($reason, 200, $checkoutStatus, $orderId, $total, $observedCurrency);
        }

        return VerificationResult::success($checkoutStatus, $orderId, $total, $observedCurrency);
    }

    /**
     * API base URL and key pair for the store's mode and the attempt's country.
     *
     * @param int $storeId
     * @param string $country
     * @return array{0: string, 1: string, 2: string}
     */
    private function credentials(int $storeId, string $country): array
    {
        $sandbox = $this->configValue(AffirmConfig::KEY_MODE, $storeId) === Credential::ACCOUNT_MODE_SANDBOX;
        $suffix = $country === AffirmConfig::COUNTRY_CODE_CAN ? AffirmConfig::SUFFIX_CANADA : '';
        $publicKey = $this->configValue(
            ($sandbox ? AffirmConfig::KEY_PUBLIC_KEY_SANDBOX : AffirmConfig::KEY_PUBLIC_KEY_PRODUCTION) . $suffix,
            $storeId
        );
        $privateKey = $this->configValue(
            ($sandbox ? AffirmConfig::KEY_PRIVATE_KEY_SANDBOX : AffirmConfig::KEY_PRIVATE_KEY_PRODUCTION) . $suffix,
            $storeId
        );
        // The config backend metadata normally returns private keys decrypted (as for Affirm's own client);
        // decrypt here only if a value still arrives in the encrypted format.
        if (preg_match(self::ENCRYPTED_PATTERN, $privateKey) === 1) {
            $privateKey = trim($this->encryptor->decrypt($privateKey));
        }

        return [
            $sandbox ? AffirmConfig::API_URL_SANDBOX : AffirmConfig::API_URL_PRODUCTION,
            $publicKey,
            $privateKey,
        ];
    }

    /**
     * Store-scoped payment/affirm_gateway value, trimmed.
     *
     * @param string $field
     * @param int $storeId
     * @return string
     */
    private function configValue(string $field, int $storeId): string
    {
        $value = $this->scopeConfig->getValue(self::CONFIG_PATH . $field, ScopeInterface::SCOPE_STORE, $storeId);

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * Order id echoed by Affirm.
     *
     * @param mixed $value
     * @return string|null
     */
    private function readOrderId(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Integer cents; anything else (fractions, text) counts as missing.
     *
     * @param mixed $value
     * @return int|null
     */
    private function readCents(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value) && is_finite($value) && floor($value) === $value) {
            return (int) $value;
        }
        if (is_string($value) && preg_match('/^-?\d{1,15}\z/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    /**
     * Log the failure (reason code and HTTP status only) and build the result.
     *
     * @param string $reason
     * @param int|null $httpStatus
     * @param string|null $checkoutStatus
     * @param string|null $orderId
     * @param int|null $total
     * @param string|null $currency
     * @return VerificationResult
     */
    private function fail(
        string $reason,
        ?int $httpStatus = null,
        ?string $checkoutStatus = null,
        ?string $orderId = null,
        ?int $total = null,
        ?string $currency = null
    ): VerificationResult {
        $this->logger->warning(
            'Affirm checkout verification failed.',
            ['reason' => $reason, 'http_status' => $httpStatus]
        );

        return VerificationResult::failure($reason, $checkoutStatus, $orderId, $total, $currency);
    }
}
