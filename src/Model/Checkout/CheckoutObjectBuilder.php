<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Model\Checkout;

use Astound\Affirm\Helper\FinancingProgram;
use Astound\Affirm\Model\Config as AffirmConfig;
use Astound\Affirm\Model\Credential;
use Hyva\CheckoutAffirm\Model\Attempt\Attempt;
use InvalidArgumentException;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\UrlInterface;
use Magento\Quote\Model\Quote;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

/**
 * Affirm checkout object for a quote whose totals are collected and whose order id is reserved.
 *
 * A pure read: nothing is reserved or saved here. The return URLs carry the attempt id in the path
 * (hyva_affirm/checkout/confirm/attempt/<id>); the redirect flow asks Affirm to return with GET so the shopper's
 * SameSite=Lax session cookie comes along. Every amount is in the quote currency, in integer cents.
 */
class CheckoutObjectBuilder implements CheckoutObjectBuilderInterface
{
    private const CONFIG_PATH = 'payment/affirm_gateway/';

    private const ROUTE_CONFIRM = 'hyva_affirm/checkout/confirm';

    private const ROUTE_CANCEL = 'hyva_affirm/checkout/cancel';

    private const MODES = [self::MODE_MODAL, self::MODE_REDIRECT, self::MODE_INLINE];

    /**
     * @param ItemsBuilder $itemsBuilder
     * @param ContactBuilder $contactBuilder
     * @param TotalsBuilder $totalsBuilder
     * @param MetadataBuilder $metadataBuilder
     * @param FinancingProgram $financingProgram inject the generated Proxy: its constructor loads the session quote
     * @param ScopeConfigInterface $scopeConfig
     * @param UrlInterface $urlBuilder
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ItemsBuilder $itemsBuilder,
        private readonly ContactBuilder $contactBuilder,
        private readonly TotalsBuilder $totalsBuilder,
        private readonly MetadataBuilder $metadataBuilder,
        private readonly FinancingProgram $financingProgram,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly UrlInterface $urlBuilder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritdoc
     *
     * @throws InvalidArgumentException for an unknown mode, or a malformed attempt id in modal or redirect mode
     */
    public function build(Quote $quote, string $mode, #[\SensitiveParameter] string $attemptId): array
    {
        $mode = strtolower(trim($mode));
        if (!in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException('Unknown Affirm checkout mode.');
        }
        if ($mode === self::MODE_INLINE) {
            $attemptId = '';
        } elseif (!Attempt::isValidId($attemptId)) {
            throw new InvalidArgumentException('A valid checkout attempt id is required for this mode.');
        }

        try {
            return $this->assemble($quote, $mode, $attemptId);
        } catch (AccountingException $e) {
            $this->logger->warning('Affirm is not offered for the cart.', $e->getLogContext() + ['mode' => $mode]);

            throw $e;
        }
    }

    /**
     * The checkout object in Affirm's field order.
     *
     * @param Quote $quote
     * @param string $mode
     * @param string $attemptId empty in inline mode
     * @return array<string, mixed>
     * @throws AccountingException
     */
    private function assemble(Quote $quote, string $mode, #[\SensitiveParameter] string $attemptId): array
    {
        $orderId = $quote->getData('reserved_order_id');
        $orderId = is_scalar($orderId) ? trim((string) $orderId) : '';
        if ($orderId === '') {
            throw new AccountingException(AccountingException::REASON_ORDER_NOT_RESERVED);
        }

        $totals = $this->totalsBuilder->build($quote);
        $items = $this->itemsBuilder->build($quote);
        $contacts = $this->contactBuilder->build($quote);

        $checkout = [
            'merchant' => $this->merchant($quote, $totals['currency'], $mode, $attemptId),
            'shipping' => $contacts['shipping'],
            'billing' => $contacts['billing'],
            'items' => $items,
        ];
        if ($totals['discounts'] !== []) {
            $checkout['discounts'] = $totals['discounts'];
        }
        $checkout['metadata'] = $this->metadataBuilder->build($quote, $mode);
        $checkout['order_id'] = $orderId;
        $checkout['shipping_amount'] = $totals['shipping_amount'];
        $checkout['tax_amount'] = $totals['tax_amount'];
        $checkout['total'] = $totals['total'];
        $checkout['currency'] = $totals['currency'];

        $financingProgram = $this->financingProgram();
        if ($financingProgram !== '') {
            $checkout['financing_program'] = $financingProgram;
        }

        return $checkout;
    }

    /**
     * Merchant object: public key for the quote's store and currency, return URLs, store name.
     *
     * @param Quote $quote
     * @param string $currency
     * @param string $mode
     * @param string $attemptId
     * @return array<string, string>
     * @throws AccountingException
     */
    private function merchant(
        Quote $quote,
        string $currency,
        string $mode,
        #[\SensitiveParameter] string $attemptId
    ): array {
        $params = ['_secure' => true, '_nosid' => true];
        if ($attemptId !== '') {
            $params['attempt'] = $attemptId;
        }

        $merchant = [
            'public_api_key' => $this->publicApiKey((int) $quote->getStoreId(), $currency),
            'user_confirmation_url' => $this->urlBuilder->getUrl(self::ROUTE_CONFIRM, $params),
            'user_cancel_url' => $this->urlBuilder->getUrl(self::ROUTE_CANCEL, $params),
            'user_decline_url' => $this->urlBuilder->getUrl(self::ROUTE_CANCEL, $params + ['reason' => 'declined']),
        ];
        if ($mode === self::MODE_REDIRECT) {
            $merchant['user_confirmation_url_action'] = 'GET';
        }
        $name = $this->storeName($quote);
        if ($name !== '') {
            $merchant['name'] = $name;
        }

        return $merchant;
    }

    /**
     * Public API key for the store's mode and the currency's region (CAD uses the "_ca" keys).
     *
     * @param int $storeId
     * @param string $currency
     * @return string
     * @throws AccountingException
     */
    private function publicApiKey(int $storeId, string $currency): string
    {
        $sandbox = $this->configValue(AffirmConfig::KEY_MODE, $storeId) === Credential::ACCOUNT_MODE_SANDBOX;
        $suffix = $currency === AffirmConfig::CURRENCY_CODE_CAD ? AffirmConfig::SUFFIX_CANADA : '';
        $key = $this->configValue(
            ($sandbox ? AffirmConfig::KEY_PUBLIC_KEY_SANDBOX : AffirmConfig::KEY_PUBLIC_KEY_PRODUCTION) . $suffix,
            $storeId
        );
        if ($key === '') {
            throw new AccountingException(AccountingException::REASON_NOT_CONFIGURED);
        }

        return $key;
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
     * Store name shown by Affirm; optional.
     *
     * @param Quote $quote
     * @return string
     */
    private function storeName(Quote $quote): string
    {
        try {
            $name = $quote->getStore()->getFrontendName();
        } catch (\Throwable) {
            return '';
        }

        return is_scalar($name) ? trim((string) $name) : '';
    }

    /**
     * Affirm's financing program for the cart (its MFP rules), sent only when set, as Affirm's checkout does.
     *
     * A failure declines the cart rather than silently offering the shopper different terms.
     *
     * @return string
     * @throws AccountingException
     */
    private function financingProgram(): string
    {
        try {
            $value = $this->financingProgram->getFinancingProgramValue();
        } catch (\Throwable $e) {
            throw new AccountingException(
                AccountingException::REASON_FINANCING_PROGRAM,
                null,
                ['error' => get_class($e)]
            );
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
