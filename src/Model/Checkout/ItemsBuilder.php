<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Model\Checkout;

use Astound\Affirm\Gateway\Helper\Util;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Image\UrlBuilder;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use Psr\Log\LoggerInterface;

/**
 * Affirm item objects: integer cents and integer quantities.
 *
 * Rows follow how Magento prices the item: a configurable is its parent row with the selected child's SKU, a
 * fixed-price bundle is its parent row, and a dynamic-price bundle (children calculated separately) becomes its
 * priced children with quantity = child quantity x bundle quantity. unit_price is the item price excluding tax
 * and before discounts, rounded half up to cents: (int) round(round(price, 2) * 100). The sum of the rows may
 * differ from the subtotal by at most one cent per row; anything larger declines the cart.
 *
 * Affirm keys checkout items by SKU, so every SKU is sent once (see uniqueSkus()).
 */
class ItemsBuilder
{
    private const TYPE_CONFIGURABLE = 'configurable';

    private const NO_IMAGE = 'no_selection';

    private const QTY_TOLERANCE = 0.0001;

    /**
     * @param UrlBuilder $imageUrlBuilder
     * @param Util $util
     * @param PriceCurrencyInterface $priceCurrency
     * @param LoggerInterface $logger
     * @param string $imageId view.xml image id used for item_image_url
     */
    public function __construct(
        private readonly UrlBuilder $imageUrlBuilder,
        private readonly Util $util,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly LoggerInterface $logger,
        private readonly string $imageId = 'product_thumbnail_image'
    ) {
    }

    /**
     * Item rows of the checkout object.
     *
     * @param Quote $quote quote with collected totals
     * @return list<array<string, int|string>>
     * @throws AccountingException
     */
    public function build(Quote $quote): array
    {
        $rows = [];
        foreach ($quote->getAllVisibleItems() as $item) {
            foreach ($this->rows($item) as $row) {
                $rows[] = $row;
            }
        }
        if ($rows === []) {
            throw new AccountingException(AccountingException::REASON_NO_ITEMS);
        }
        $items = $this->uniqueSkus($rows);
        $this->reconcile($quote, $items, count($rows));

        return $items;
    }

    /**
     * One row per SKU, because Affirm keys checkout items by SKU.
     *
     * Affirm merges rows that share a SKU into one item with the first row's unit price, so two lines "C" at
     * 20.00 and 100.00 would be itemized as 2 x 20.00. Instead:
     * - rows with the same SKU and the same unit price become one row: quantities are added, and the first row's
     *   name, image and URL are kept;
     * - when a SKU comes with different unit prices, the first price keeps the SKU and each further price, in
     *   order of first appearance, is sent as the SKU with "-2", "-3", ... appended; a suffix that would equal
     *   another SKU of the cart (or one already assigned) is skipped for the next number.
     * No unit price or quantity changes, so Affirm's itemization adds up to the same amount as the cart's rows.
     *
     * @param list<array<string,int|string>> $rows
     * @return list<array<string,int|string>>
     */
    private function uniqueSkus(array $rows): array
    {
        $taken = [];
        foreach ($rows as $row) {
            $taken[(string) $row['sku']] = true;
        }

        $items = [];
        // SKU as built => [unit price => index in $items], in order of first appearance.
        $positions = [];
        foreach ($rows as $row) {
            $sku = (string) $row['sku'];
            $price = (int) $row['unit_price'];
            if (isset($positions[$sku][$price])) {
                $index = $positions[$sku][$price];
                $items[$index]['qty'] = (int) $items[$index]['qty'] + (int) $row['qty'];
                continue;
            }
            if (isset($positions[$sku])) {
                $suffix = count($positions[$sku]) + 1;
                while (isset($taken[$sku . '-' . $suffix])) {
                    $suffix++;
                }
                $row['sku'] = $sku . '-' . $suffix;
                $taken[$row['sku']] = true;
            }
            $positions[$sku][$price] = count($items);
            $items[] = $row;
        }

        return $items;
    }

    /**
     * Rows for one visible quote item.
     *
     * @param AbstractItem $item
     * @return list<array<string, int|string>>
     * @throws AccountingException
     */
    private function rows(AbstractItem $item): array
    {
        $children = array_values((array) $item->getChildren());
        if ($children !== [] && $item->isChildrenCalculated()) {
            $parentQty = $this->number($item->getData('qty'));
            $rows = [];
            foreach ($children as $child) {
                $rows[] = $this->row(
                    $child,
                    $this->quantity($this->number($child->getData('qty')) * $parentQty),
                    $this->text($child->getData('sku')),
                    [$child->getProduct(), $item->getProduct()],
                    $item->getProduct()
                );
            }

            return $rows;
        }

        $sku = $this->text($item->getData('sku'));
        $imageProducts = [$item->getProduct()];
        if ($children !== [] && $this->text($item->getData('product_type')) === self::TYPE_CONFIGURABLE) {
            $childSku = $this->text($children[0]->getData('sku'));
            $sku = $childSku !== '' ? $childSku : $sku;
            array_unshift($imageProducts, $children[0]->getProduct());
        }

        return [
            $this->row($item, $this->quantity($this->number($item->getData('qty'))), $sku, $imageProducts, null),
        ];
    }

    /**
     * One Affirm item object.
     *
     * @param AbstractItem $item item that carries name and price
     * @param int $qty
     * @param string $sku
     * @param array<int,Product|null> $imageProducts products to take the image from, in order of preference
     * @param Product|null $urlProduct product to link to; the item's own product when null
     * @return array<string, int|string>
     */
    private function row(AbstractItem $item, int $qty, string $sku, array $imageProducts, ?Product $urlProduct): array
    {
        $name = $this->text($item->getData('name'));
        $row = [
            'display_name' => $name !== '' ? $name : $sku,
            'sku' => $sku,
            'unit_price' => (int) round(round($this->number($item->getData('price')), 2) * 100),
            'qty' => $qty,
        ];

        $imageUrl = $this->imageUrl($imageProducts);
        if ($imageUrl !== '') {
            $row['item_image_url'] = $imageUrl;
        }
        $itemUrl = $this->productUrl($urlProduct ?? $item->getProduct());
        if ($itemUrl !== '') {
            $row['item_url'] = $itemUrl;
        }

        return $row;
    }

    /**
     * Compare the rows that are sent with the collected subtotal: at most one cent of rounding per row.
     *
     * The tolerance counts the rows before uniqueSkus() merged any: each merged row carries the rounding of every
     * row it absorbed, so merging never declines a cart that would otherwise be accepted.
     *
     * @param Quote $quote
     * @param list<array<string,int|string>> $rows the rows that are sent
     * @param int $roundedRows number of rows whose unit price was rounded
     * @return void
     * @throws AccountingException
     */
    private function reconcile(Quote $quote, array $rows, int $roundedRows): void
    {
        $sum = 0;
        foreach ($rows as $row) {
            $sum += (int) $row['unit_price'] * (int) $row['qty'];
        }
        $address = $quote->isVirtual() ? $quote->getBillingAddress() : $quote->getShippingAddress();
        $subtotal = $this->priceCurrency->round($this->number($address->getData('subtotal')));
        // @phpstan-ignore argument.type (Affirm documents int but formats any amount with sprintf('%.2f'))
        $subtotal = (int) $this->util->formatToCents($subtotal);

        $difference = $sum - $subtotal;
        $diagnostics = ['difference_cents' => $difference, 'rows' => $roundedRows];
        if (abs($difference) > $roundedRows) {
            throw new AccountingException(AccountingException::REASON_ITEMS_MISMATCH, null, $diagnostics);
        }
        if ($difference !== 0) {
            $this->logger->info('Affirm checkout items differ from the subtotal by rounding.', $diagnostics);
        }
    }

    /**
     * Positive whole quantity; anything else declines the cart.
     *
     * @param float $qty
     * @return int
     * @throws AccountingException
     */
    private function quantity(float $qty): int
    {
        $rounded = round($qty);
        if ($rounded < 1 || abs($qty - $rounded) > self::QTY_TOLERANCE) {
            throw new AccountingException(AccountingException::REASON_NON_INTEGER_QTY);
        }

        return (int) $rounded;
    }

    /**
     * Thumbnail URL of the first product that has one; images are optional for Affirm.
     *
     * @param array<int,Product|null> $products
     * @return string
     */
    private function imageUrl(array $products): string
    {
        foreach ($products as $product) {
            $file = $product instanceof Product ? $this->text($product->getData('thumbnail')) : '';
            if ($file === '' || $file === self::NO_IMAGE) {
                continue;
            }
            try {
                return $this->imageUrlBuilder->getUrl($file, $this->imageId);
            } catch (\Throwable $e) {
                $this->logger->warning('Affirm checkout item image URL unavailable.', ['exception_class' => get_class($e)]);

                return '';
            }
        }

        return '';
    }

    /**
     * Product page URL; optional for Affirm, so a failure only drops the field.
     *
     * @param Product|null $product
     * @return string
     */
    private function productUrl(?Product $product): string
    {
        if ($product === null) {
            return '';
        }
        try {
            return $this->text($product->getProductUrl());
        } catch (\Throwable $e) {
            $this->logger->warning('Affirm checkout item URL unavailable.', ['exception_class' => get_class($e)]);

            return '';
        }
    }

    /**
     * Numeric value as float, 0 when absent.
     *
     * @param mixed $value
     * @return float
     */
    private function number(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
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
