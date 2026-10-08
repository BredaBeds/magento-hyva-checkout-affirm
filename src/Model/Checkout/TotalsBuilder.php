<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Model\Checkout;

use Astound\Affirm\Gateway\Helper\Util;
use Astound\Affirm\Model\Config as AffirmConfig;
use Magento\Framework\DataObject;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\TotalsCollectorList;
use Magento\SalesRule\Api\Data\RuleDiscountInterface;

/**
 * Totals of the checkout object (quote currency, integer cents) and the accounting rules behind them.
 *
 * Unsupported adjustments are found in the amounts the total collectors COLLECTED, never in the segments their
 * fetch() displays: a display-only segment (one that only shows savings, say) must not decline a cart. Magento's
 * TotalsCollector copies every collected "<code>_amount" onto the address, so the address data of each collector
 * registered for the store is checked (the address's own getAllTotalAmounts() is never filled in 2.4).
 * Adjustments applied straight to the grand total, which leave no collected amount, fail the reconciliation.
 *
 * Cents use Affirm's own Util::formatToCents() on the value Magento rounds the order total to, so the total is
 * bit-identical with the amount Affirm's gateway validates at authorization.
 */
class TotalsBuilder
{
    /**
     * Collected amounts whose sum is the grand total of a supported cart.
     */
    private const SUMMED_CODES = [
        'subtotal',
        'shipping',
        'discount',
        'tax',
        'discount_tax_compensation',
        'shipping_discount_tax_compensation',
    ];

    /**
     * Collectors whose "<code>_amount" is informational: they move amounts into the codes above
     * (shipping_discount is already part of discount) or compute the grand total itself.
     */
    private const INFORMATIONAL_CODES = ['grand_total', 'shipping_discount', 'tax_subtotal', 'tax_shipping'];

    /**
     * Fixed product taxes (weee, weee_tax) are supported and part of the grand total.
     */
    private const SUMMED_CODE_PREFIX = 'weee';

    private const SUPPORTED_CURRENCIES = [AffirmConfig::CURRENCY_CODE_USD, AffirmConfig::CURRENCY_CODE_CAD];

    /**
     * Totals are stored with four decimals; one cent is 100 of those units.
     */
    private const PRECISION = 10000;

    private const ONE_CENT = 100;

    /**
     * @var list<string>
     */
    private readonly array $ignoredTotalCodes;

    /**
     * @param TotalsCollectorList $collectorList
     * @param Util $util
     * @param PriceCurrencyInterface $priceCurrency
     * @param array<array-key,string> $ignoredTotalCodes total codes whose collected amounts never decline a cart by
     *        themselves; the grand total must still reconcile, with or without their amounts
     */
    public function __construct(
        private readonly TotalsCollectorList $collectorList,
        private readonly Util $util,
        private readonly PriceCurrencyInterface $priceCurrency,
        array $ignoredTotalCodes = []
    ) {
        $codes = [];
        foreach ($ignoredTotalCodes as $code) {
            $code = is_string($code) ? trim($code) : '';
            if ($code !== '' && !in_array($code, self::SUMMED_CODES, true)
                && !in_array($code, self::INFORMATIONAL_CODES, true)
            ) {
                $codes[$code] = $code;
            }
        }
        $this->ignoredTotalCodes = array_values($codes);
    }

    /**
     * Validate the cart's accounting and return the totals of the checkout object.
     *
     * @param Quote $quote quote with collected totals
     * @return array{currency: string, total: int, shipping_amount: int, tax_amount: int,
     *     discounts: array<string, array{discount_amount: int, discount_display_name: string}>}
     * @throws AccountingException
     */
    public function build(Quote $quote): array
    {
        $currency = $this->currency($quote);
        if ((bool) $quote->getData('is_multi_shipping')) {
            throw new AccountingException(AccountingException::REASON_MULTISHIPPING);
        }

        $address = $this->totalsAddress($quote);
        $grandTotal = $this->amount($address, 'grand_total');
        $total = $this->toCents($grandTotal);
        if ($total <= 0) {
            throw new AccountingException(AccountingException::REASON_ZERO_TOTAL);
        }
        if (!$this->withinOneCent($this->amount($quote, 'grand_total') - $grandTotal)) {
            throw new AccountingException(
                AccountingException::REASON_UNEXPLAINED_DIFFERENCE,
                null,
                ['scope' => 'quote_address']
            );
        }
        $this->assertSupportedAdjustments($quote, $address, $grandTotal);

        return [
            'currency' => $currency,
            'total' => $total,
            'shipping_amount' => $this->toCents($this->amount($address, 'shipping_amount')),
            'tax_amount' => $this->toCents($this->amount($address, 'tax_amount')),
            'discounts' => $this->discounts($address),
        ];
    }

    /**
     * Integer cents the way Affirm's validator computes them from Magento's rounded order amount.
     *
     * @param float $amount
     * @return int
     */
    public function toCents(float $amount): int
    {
        // @phpstan-ignore argument.type (Affirm documents int but formats any amount with sprintf('%.2f'))
        return (int) $this->util->formatToCents($this->priceCurrency->round($amount));
    }

    /**
     * Quote currency, which must equal the base currency and be one Affirm supports.
     *
     * @param Quote $quote
     * @return string
     * @throws AccountingException
     */
    private function currency(Quote $quote): string
    {
        $quoteCurrency = strtoupper($this->text($quote->getData('quote_currency_code')));
        $baseCurrency = strtoupper($this->text($quote->getData('base_currency_code')));
        if (!in_array($quoteCurrency, self::SUPPORTED_CURRENCIES, true)) {
            throw new AccountingException(
                AccountingException::REASON_UNSUPPORTED_CURRENCY,
                null,
                ['currency' => $quoteCurrency]
            );
        }
        if ($baseCurrency !== $quoteCurrency) {
            throw new AccountingException(
                AccountingException::REASON_CURRENCY_MISMATCH,
                null,
                ['currency' => $quoteCurrency, 'base_currency' => $baseCurrency]
            );
        }

        return $quoteCurrency;
    }

    /**
     * Decline collected adjustments outside the allow-list and grand totals the supported amounts do not explain.
     *
     * @param Quote $quote
     * @param Quote\Address $address
     * @param float $grandTotal
     * @return void
     * @throws AccountingException
     */
    private function assertSupportedAdjustments(Quote $quote, Quote\Address $address, float $grandTotal): void
    {
        $summed = self::SUMMED_CODES;
        $unsupported = [];
        foreach ($this->collectorList->getCollectors((int) $quote->getStoreId()) as $collector) {
            $code = $this->text($collector->getCode());
            if ($code === ''
                || in_array($code, self::SUMMED_CODES, true)
                || in_array($code, self::INFORMATIONAL_CODES, true)
                || in_array($code, $this->ignoredTotalCodes, true)
            ) {
                continue;
            }
            if (str_starts_with($code, self::SUMMED_CODE_PREFIX)) {
                $summed[] = $code;
                continue;
            }
            if (!$this->withinPrecision($this->collected($address, $code))) {
                $unsupported[] = $code;
            }
        }
        if ($this->amount($address, 'discount_amount') > 0) {
            // A positive discount is a surcharge in disguise.
            $unsupported[] = 'discount';
        }
        if ($unsupported !== []) {
            throw new AccountingException(
                AccountingException::REASON_UNSUPPORTED_ADJUSTMENT,
                null,
                ['codes' => array_values(array_unique($unsupported))]
            );
        }

        $difference = $grandTotal - $this->sum($address, $summed);
        $ignored = $this->sum($address, $this->ignoredTotalCodes);
        if ($this->withinOneCent($difference)
            || (!$this->withinPrecision($ignored) && $this->withinOneCent($difference - $ignored))
        ) {
            return;
        }

        throw new AccountingException(
            AccountingException::REASON_UNEXPLAINED_DIFFERENCE,
            null,
            ['difference_cents' => round($difference * 100, 2)]
        );
    }

    /**
     * Affirm discount objects: one per sales rule when the rules add up to the discount exactly, else one entry.
     *
     * The discount amount includes shipping discounts. Nothing else is ever reported as a discount.
     *
     * @param Quote\Address $address
     * @return array<string, array{discount_amount: int, discount_display_name: string}>
     */
    private function discounts(Quote\Address $address): array
    {
        $totalCents = $this->toCents(abs($this->amount($address, 'discount_amount')));
        if ($totalCents === 0) {
            return [];
        }

        $perRule = $this->discountsPerRule($address);
        if ($perRule !== [] && array_sum($perRule) === $totalCents) {
            $discounts = [];
            foreach ($perRule as $label => $cents) {
                $discounts[$this->discountKey((string) $label)] = [
                    'discount_amount' => $cents,
                    'discount_display_name' => (string) $label,
                ];
            }

            return $discounts;
        }

        $label = $this->text($address->getData('discount_description'));
        $label = $label !== '' ? $label : (string) __('Discount');

        return [
            $this->discountKey($label) => ['discount_amount' => $totalCents, 'discount_display_name' => $label],
        ];
    }

    /**
     * Discount cents per rule label from the sales-rule breakdown on the address (present after collectTotals()).
     *
     * @param Quote\Address $address
     * @return array<string, int>
     */
    private function discountsPerRule(Quote\Address $address): array
    {
        $extension = $address->getExtensionAttributes();
        $breakdown = is_object($extension) && method_exists($extension, 'getDiscounts')
            ? $extension->getDiscounts()
            : null;
        if (!is_array($breakdown) || $breakdown === []) {
            return [];
        }

        $rules = [];
        foreach ($breakdown as $discount) {
            if (!$discount instanceof RuleDiscountInterface || $discount->getDiscountData() === null) {
                return [];
            }
            $ruleId = (string) $discount->getRuleID();
            $rules[$ruleId]['label'] ??= $this->text($discount->getRuleLabel());
            $rules[$ruleId]['amount'] = ($rules[$ruleId]['amount'] ?? 0.0)
                + abs((float) $discount->getDiscountData()->getAmount());
        }

        $perLabel = [];
        foreach ($rules as $rule) {
            $cents = $this->toCents($rule['amount']);
            if ($cents === 0) {
                continue;
            }
            $label = $rule['label'] !== '' ? $rule['label'] : (string) __('Discount');
            $perLabel[$label] = ($perLabel[$label] ?? 0) + $cents;
        }

        return $perLabel;
    }

    /**
     * Discount object key; numeric keys would turn the JSON object into an array.
     *
     * @param string $label
     * @return string
     */
    private function discountKey(string $label): string
    {
        return ctype_digit($label) ? (string) __('Discount') . ' ' . $label : $label;
    }

    /**
     * The address that carries the totals: billing for virtual quotes, shipping otherwise.
     *
     * @param Quote $quote
     * @return Quote\Address
     */
    private function totalsAddress(Quote $quote): Quote\Address
    {
        return $quote->isVirtual() ? $quote->getBillingAddress() : $quote->getShippingAddress();
    }

    /**
     * Collected amount of a total code as TotalsCollector left it on the address.
     *
     * @param Quote\Address $address
     * @param string $code
     * @return float
     */
    private function collected(Quote\Address $address, string $code): float
    {
        return $this->amount($address, $code === 'subtotal' ? 'subtotal' : $code . '_amount');
    }

    /**
     * Sum of the collected amounts of distinct codes.
     *
     * @param Quote\Address $address
     * @param list<string> $codes
     * @return float
     */
    private function sum(Quote\Address $address, array $codes): float
    {
        $sum = 0.0;
        foreach (array_unique($codes) as $code) {
            $sum += $this->collected($address, $code);
        }

        return $sum;
    }

    /**
     * Numeric data value, 0 when absent.
     *
     * @param DataObject $object
     * @param string $key
     * @return float
     */
    private function amount(DataObject $object, string $key): float
    {
        $value = $object->getData($key);

        return is_numeric($value) ? (float) $value : 0.0;
    }

    /**
     * Whether an amount difference is at most one cent.
     *
     * @param float $difference
     * @return bool
     */
    private function withinOneCent(float $difference): bool
    {
        return abs((int) round($difference * self::PRECISION)) <= self::ONE_CENT;
    }

    /**
     * Whether an amount is zero at Magento's storage precision.
     *
     * @param float $amount
     * @return bool
     */
    private function withinPrecision(float $amount): bool
    {
        return (int) round($amount * self::PRECISION) === 0;
    }

    /**
     * Trimmed string; non-scalars become empty.
     *
     * @param mixed $value
     * @return string
     */
    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
