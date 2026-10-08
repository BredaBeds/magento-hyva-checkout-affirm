<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Model\Attempt;

use Magento\Framework\DataObject;
use Magento\Quote\Model\Quote;

/**
 * Canonical cart snapshot: store, currencies, items, addresses, shipping and payment method, totals,
 * applied rules, coupon and customer e-mail. Amounts are normalised to four decimals so values read from the
 * database and values from a fresh collectTotals() hash the same.
 */
class Snapshot implements SnapshotInterface
{
    /**
     * Bump when the collected fields change, so old hashes never match new ones by accident.
     */
    private const FORMAT_VERSION = 1;

    private const ADDRESS_FIELDS = [
        'firstname',
        'lastname',
        'street',
        'city',
        'region',
        'region_id',
        'postcode',
        'country_id',
        'telephone',
        'email',
    ];

    /**
     * @inheritdoc
     */
    public function hash(Quote $quote): string
    {
        $json = json_encode(
            $this->collect($quote),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );

        return hash('sha256', $json);
    }

    /**
     * Snapshot data in a fixed order.
     *
     * Public so an after-plugin can add or drop fields (for example tax, when an external tax service may answer
     * differently between two calls).
     *
     * @param Quote $quote
     * @return array<string, mixed>
     */
    public function collect(Quote $quote): array
    {
        $shipping = $quote->getShippingAddress();
        $billing = $quote->getBillingAddress();
        // Virtual quotes keep their totals on the billing address.
        $totals = $quote->isVirtual() ? $billing : $shipping;

        return [
            'v' => self::FORMAT_VERSION,
            'store_id' => (int) $quote->getStoreId(),
            'quote_currency' => $this->text($quote->getData('quote_currency_code')),
            'base_currency' => $this->text($quote->getData('base_currency_code')),
            'items' => $this->items($quote),
            'shipping_address' => $this->address($shipping),
            'billing_address' => $this->address($billing),
            'shipping_method' => $this->text($shipping->getData('shipping_method')),
            'payment_method' => $this->text($quote->getPayment()->getData('method')),
            'grand_total' => $this->amount($quote->getData('grand_total')),
            'base_grand_total' => $this->amount($quote->getData('base_grand_total')),
            'subtotal' => $this->amount($quote->getData('subtotal')),
            'shipping_amount' => $this->amount($totals->getData('shipping_amount')),
            'tax_amount' => $this->amount($totals->getData('tax_amount')),
            'discount_amount' => $this->amount($totals->getData('discount_amount')),
            'applied_rule_ids' => $this->ruleIds($quote->getData('applied_rule_ids')),
            'coupon_code' => $this->text($quote->getData('coupon_code')),
            'customer_email' => strtolower($this->text($quote->getData('customer_email'))),
        ];
    }

    /**
     * Items (parents and children), sorted so collection order does not matter.
     *
     * @param Quote $quote
     * @return list<array<string, int|string|null>>
     */
    private function items(Quote $quote): array
    {
        $items = [];
        foreach ($quote->getAllItems() as $item) {
            $items[] = [
                'sku' => $this->text($item->getData('sku')),
                'product_id' => $this->id($item->getData('product_id')),
                'qty' => $this->amount($item->getData('qty')),
                'row_total' => $this->amount($item->getData('row_total')),
                'parent_item_id' => $this->id($item->getData('parent_item_id')),
            ];
        }
        usort(
            $items,
            static fn (array $left, array $right): int => strcmp(
                json_encode($left, JSON_THROW_ON_ERROR),
                json_encode($right, JSON_THROW_ON_ERROR)
            )
        );

        return $items;
    }

    /**
     * Address fields Affirm receives.
     *
     * @param DataObject $address
     * @return array<string, int|string|null>
     */
    private function address(DataObject $address): array
    {
        $data = [];
        foreach (self::ADDRESS_FIELDS as $field) {
            $value = $address->getData($field);
            if ($field === 'street') {
                $lines = is_array($value) ? $value : explode("\n", $this->text($value));
                $value = implode("\n", array_map(fn (mixed $line): string => $this->text($line), $lines));
            }
            $data[$field] = $field === 'region_id' ? $this->id($value) : $this->text($value);
        }

        return $data;
    }

    /**
     * Comma-separated rule ids in ascending order.
     *
     * @param mixed $value
     * @return string
     */
    private function ruleIds(mixed $value): string
    {
        $ids = array_filter(
            array_map('trim', explode(',', $this->text($value))),
            static fn (string $id): bool => $id !== ''
        );
        sort($ids, SORT_NATURAL);

        return implode(',', $ids);
    }

    /**
     * Money or quantity with four decimals.
     *
     * @param mixed $value
     * @return string
     */
    private function amount(mixed $value): string
    {
        return number_format(is_numeric($value) ? (float) $value : 0.0, 4, '.', '');
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

    /**
     * Positive integer id or null.
     *
     * @param mixed $value
     * @return int|null
     */
    private function id(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
