<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Test\Unit\Model\Checkout;

use Astound\Affirm\Gateway\Helper\Util;
use Hyva\CheckoutAffirm\Model\Checkout\AccountingException;
use Hyva\CheckoutAffirm\Model\Checkout\ItemsBuilder;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Image\UrlBuilder;
use Magento\Framework\Math\Random;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Item;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ItemsBuilderTest extends TestCase
{
    private const MEDIA = 'https://shop.example/media/catalog/product/cache/thumb';

    /**
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface $logger;

    /**
     * @var UrlBuilder&MockObject
     */
    private UrlBuilder $imageUrlBuilder;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->imageUrlBuilder = $this->createMock(UrlBuilder::class);
        $this->imageUrlBuilder->method('getUrl')->willReturnCallback(
            static function (string $file, string $imageId): string {
                self::assertSame('product_thumbnail_image', $imageId);

                return self::MEDIA . $file;
            }
        );
    }

    public function testSimpleVirtualAndDownloadableRows(): void
    {
        $items = [
            $this->item(['sku' => 'BED-Q', 'name' => 'Queen bed', 'price' => '1000.0000', 'qty' => '1.0000'], 'bed'),
            $this->item(
                ['sku' => 'WARRANTY', 'name' => 'Warranty', 'price' => '99', 'qty' => '1', 'product_type' => 'virtual'],
                'warranty'
            ),
            $this->item(
                ['sku' => 'GUIDE', 'name' => 'Guide', 'price' => '5.5', 'qty' => 2, 'product_type' => 'downloadable'],
                null
            ),
        ];

        $rows = $this->builder()->build($this->quote($items, '1110.0000'));

        $this->assertSame(
            [
                [
                    'display_name' => 'Queen bed',
                    'sku' => 'BED-Q',
                    'unit_price' => 100000,
                    'qty' => 1,
                    'item_image_url' => self::MEDIA . '/b/e/bed.jpg',
                    'item_url' => 'https://shop.example/bed.html',
                ],
                [
                    'display_name' => 'Warranty',
                    'sku' => 'WARRANTY',
                    'unit_price' => 9900,
                    'qty' => 1,
                    'item_image_url' => self::MEDIA . '/w/a/warranty.jpg',
                    'item_url' => 'https://shop.example/warranty.html',
                ],
                [
                    'display_name' => 'Guide',
                    'sku' => 'GUIDE',
                    'unit_price' => 550,
                    'qty' => 2,
                ],
            ],
            $rows
        );
    }

    public function testConfigurableIsTheParentRowWithTheChildSkuAndImage(): void
    {
        $child = $this->item(['sku' => 'SHIRT-RED-M', 'name' => 'Shirt', 'price' => '0', 'qty' => '1'], 'shirt-red');
        $parent = $this->item(
            ['sku' => 'SHIRT', 'name' => 'Shirt', 'price' => '25.0000', 'qty' => '2', 'product_type' => 'configurable'],
            'shirt',
            [$child]
        );

        $rows = $this->builder()->build($this->quote([$parent], '50.0000'));

        $this->assertCount(1, $rows);
        $this->assertSame('SHIRT-RED-M', $rows[0]['sku']);
        $this->assertSame(2500, $rows[0]['unit_price']);
        $this->assertSame(2, $rows[0]['qty']);
        $this->assertSame(self::MEDIA . '/s/h/shirt-red.jpg', $rows[0]['item_image_url']);
        $this->assertSame('https://shop.example/shirt.html', $rows[0]['item_url']);
    }

    public function testConfigurableFallsBackToTheParentImage(): void
    {
        $child = $this->item(['sku' => 'SHIRT-RED-M', 'price' => '0', 'qty' => '1'], null);
        $parent = $this->item(
            ['sku' => 'SHIRT', 'name' => 'Shirt', 'price' => '25', 'qty' => '1', 'product_type' => 'configurable'],
            'shirt',
            [$child]
        );

        $rows = $this->builder()->build($this->quote([$parent], '25.0000'));

        $this->assertSame(self::MEDIA . '/s/h/shirt.jpg', $rows[0]['item_image_url']);
    }

    public function testFixedPriceBundleIsTheParentRow(): void
    {
        $children = [
            $this->item(['sku' => 'FRAME', 'price' => '0', 'qty' => '1'], 'frame'),
            $this->item(['sku' => 'SLATS', 'price' => '0', 'qty' => '2'], 'slats'),
        ];
        $bundle = $this->item(
            ['sku' => 'BED-KIT', 'name' => 'Bed kit', 'price' => '799.0000', 'qty' => '3', 'product_type' => 'bundle'],
            'kit',
            $children,
            false
        );

        $rows = $this->builder()->build($this->quote([$bundle], '2397.0000'));

        $this->assertCount(1, $rows);
        $this->assertSame(
            ['display_name' => 'Bed kit', 'sku' => 'BED-KIT', 'unit_price' => 79900, 'qty' => 3],
            array_intersect_key($rows[0], array_flip(['display_name', 'sku', 'unit_price', 'qty']))
        );
    }

    public function testDynamicBundleSendsItsPricedChildrenWithMultipliedQuantities(): void
    {
        $children = [
            $this->item(['sku' => 'FRAME', 'name' => 'Frame', 'price' => '400.0000', 'qty' => '1'], 'frame'),
            $this->item(['sku' => 'SLATS', 'name' => 'Slat', 'price' => '12.5000', 'qty' => '3'], null),
        ];
        $bundle = $this->item(
            ['sku' => 'BED-KIT', 'name' => 'Bed kit', 'price' => '437.5000', 'qty' => '2', 'product_type' => 'bundle'],
            'kit',
            $children,
            true
        );

        $rows = $this->builder()->build($this->quote([$bundle], '875.0000'));

        $this->assertSame(
            [
                [
                    'display_name' => 'Frame',
                    'sku' => 'FRAME',
                    'unit_price' => 40000,
                    'qty' => 2,
                    'item_image_url' => self::MEDIA . '/f/r/frame.jpg',
                    'item_url' => 'https://shop.example/kit.html',
                ],
                [
                    'display_name' => 'Slat',
                    'sku' => 'SLATS',
                    'unit_price' => 1250,
                    'qty' => 6,
                    'item_image_url' => self::MEDIA . '/k/i/kit.jpg',
                    'item_url' => 'https://shop.example/kit.html',
                ],
            ],
            $rows
        );
    }

    public function testDynamicBundleAcceptsAFractionalChildQuantityThatMultipliesToAWholeOne(): void
    {
        $children = [$this->item(['sku' => 'FABRIC', 'name' => 'Fabric', 'price' => '10', 'qty' => '1.5'], null)];
        $bundle = $this->item(
            ['sku' => 'KIT', 'price' => '15', 'qty' => '2', 'product_type' => 'bundle'],
            null,
            $children,
            true
        );

        $rows = $this->builder()->build($this->quote([$bundle], '30.0000'));

        $this->assertSame(3, $rows[0]['qty']);
    }

    /**
     * @return array<string, array{0: string|int|float|null, 1: string|null}>
     */
    public static function invalidQuantities(): array
    {
        return [
            'fraction' => ['1.5', null],
            'tiny fraction' => ['2.001', null],
            'zero' => ['0', null],
            'negative' => ['-1', null],
            'missing' => [null, null],
            'bundle child times parent' => ['1', '1.5'],
        ];
    }

    #[DataProvider('invalidQuantities')]
    public function testNonIntegerQuantityIsDeclined(string|int|float|null $qty, ?string $childQty): void
    {
        if ($childQty === null) {
            $item = $this->item(['sku' => 'ROPE', 'price' => '2', 'qty' => $qty], null);
        } else {
            $child = $this->item(['sku' => 'ROPE', 'price' => '2', 'qty' => $childQty], null);
            $item = $this->item(['sku' => 'KIT', 'price' => '3', 'qty' => $qty], null, [$child], true);
        }

        $this->assertDeclined($this->quote([$item], '3.0000'), AccountingException::REASON_NON_INTEGER_QTY);
    }

    public function testQuantityWithinStoragePrecisionIsAccepted(): void
    {
        $item = $this->item(['sku' => 'ROPE', 'price' => '2', 'qty' => 2.99999], null);

        $this->assertSame(3, $this->builder()->build($this->quote([$item], '6.0000'))[0]['qty']);
    }

    /**
     * @return array<string, array{0: string|float, 1: int}>
     */
    public static function unitPrices(): array
    {
        return [
            '1.005' => [1.005, 101],
            '2.675' => [2.675, 268],
            '0.285' => [0.285, 29],
            '10.125' => [10.125, 1013],
            'stored 33.3350' => ['33.3350', 3334],
            'stored 33.3349' => ['33.3349', 3333],
            '99.995' => [99.995, 10000],
        ];
    }

    #[DataProvider('unitPrices')]
    public function testUnitPriceRoundsHalfUpToCents(string|float $price, int $cents): void
    {
        $item = $this->item(['sku' => 'X', 'price' => $price, 'qty' => '1'], null);

        $rows = $this->builder()->build($this->quote([$item], number_format($cents / 100, 4, '.', '')));

        $this->assertSame($cents, $rows[0]['unit_price']);
    }

    public function testRoundingWithinOneCentPerRowIsLogged(): void
    {
        $items = [
            $this->item(['sku' => 'A', 'price' => '33.3333', 'qty' => '1'], null),
            $this->item(['sku' => 'B', 'price' => '33.3333', 'qty' => '1'], null),
            $this->item(['sku' => 'C', 'price' => '33.3334', 'qty' => '1'], null),
        ];
        $this->logger->expects($this->once())
            ->method('info')
            ->with($this->anything(), ['difference_cents' => -1, 'rows' => 3]);

        $rows = $this->builder()->build($this->quote($items, '100.0000'));

        $this->assertSame([3333, 3333, 3333], array_column($rows, 'unit_price'));
    }

    public function testExactMatchIsNotLogged(): void
    {
        $this->logger->expects($this->never())->method('info');

        $item = $this->item(['sku' => 'A', 'price' => '10', 'qty' => '3'], null);

        $this->builder()->build($this->quote([$item], '30'));
    }

    public function testDifferenceAboveOneCentPerRowIsDeclined(): void
    {
        $items = [
            $this->item(['sku' => 'A', 'price' => '10.0000', 'qty' => '1'], null),
            $this->item(['sku' => 'B', 'price' => '10.0000', 'qty' => '1'], null),
        ];

        $exception = $this->assertDeclined($this->quote($items, '20.0300'), AccountingException::REASON_ITEMS_MISMATCH);

        $this->assertSame(['difference_cents' => -3, 'rows' => 2], $exception->getDiagnostics());
    }

    public function testVirtualQuoteComparesWithTheBillingSubtotal(): void
    {
        $item = $this->item(['sku' => 'WARRANTY', 'price' => '99', 'qty' => '1'], null);

        $rows = $this->builder()->build($this->quote([$item], '99.0000', true));

        $this->assertSame(9900, $rows[0]['unit_price']);
    }

    public function testEmptyCartIsDeclined(): void
    {
        $this->assertDeclined($this->quote([], '0'), AccountingException::REASON_NO_ITEMS);
    }

    public function testNameFallsBackToSku(): void
    {
        $item = $this->item(['sku' => 'X1', 'price' => '1', 'qty' => '1'], null);

        $rows = $this->builder()->build($this->quote([$item], '1'));

        $this->assertSame('X1', $rows[0]['display_name']);
    }

    public function testImageAndUrlFailuresOnlyDropTheFields(): void
    {
        $imageUrlBuilder = $this->createMock(UrlBuilder::class);
        $imageUrlBuilder->method('getUrl')->willThrowException(new \RuntimeException('no view config'));
        $item = $this->item(['sku' => 'BED', 'price' => '10', 'qty' => '1'], 'bed', [], false, true);
        $this->logger->expects($this->exactly(2))->method('warning');

        $rows = (new ItemsBuilder($imageUrlBuilder, $this->util(), $this->priceCurrency(), $this->logger))
            ->build($this->quote([$item], '10'));

        $this->assertSame(['display_name' => 'BED', 'sku' => 'BED', 'unit_price' => 1000, 'qty' => 1], $rows[0]);
    }

    public function testRowsWithTheSameSkuAndUnitPriceAreMergedIntoTheFirstRow(): void
    {
        $items = [
            $this->item(['sku' => 'C', 'name' => 'Custom A', 'price' => '20.0000', 'qty' => '1'], 'custom-a'),
            $this->item(['sku' => 'BED', 'name' => 'Bed', 'price' => '100', 'qty' => '1'], null),
            $this->item(['sku' => 'C', 'name' => 'Custom B', 'price' => '20', 'qty' => '2'], 'custom-b'),
        ];
        $this->logger->expects($this->never())->method('info');

        $rows = $this->builder()->build($this->quote($items, '160.0000'));

        $this->assertSame(
            [
                [
                    'display_name' => 'Custom A',
                    'sku' => 'C',
                    'unit_price' => 2000,
                    'qty' => 3,
                    'item_image_url' => self::MEDIA . '/c/u/custom-a.jpg',
                    'item_url' => 'https://shop.example/custom-a.html',
                ],
                ['display_name' => 'Bed', 'sku' => 'BED', 'unit_price' => 10000, 'qty' => 1],
            ],
            $rows
        );
    }

    public function testTheSameSkuWithDifferentUnitPricesGetsASuffixPerFurtherPrice(): void
    {
        $items = [
            $this->item(['sku' => 'BED-Q', 'name' => 'Queen bed', 'price' => '1832', 'qty' => '1'], null),
            $this->item(['sku' => 'C', 'name' => 'Custom Product', 'price' => '20', 'qty' => '1'], null),
            $this->item(['sku' => 'C', 'name' => 'Custom Product', 'price' => '100', 'qty' => '1'], null),
        ];
        $this->logger->expects($this->never())->method('info');

        $rows = $this->builder()->build($this->quote($items, '1952.0000'));

        $this->assertSame(
            [
                ['display_name' => 'Queen bed', 'sku' => 'BED-Q', 'unit_price' => 183200, 'qty' => 1],
                ['display_name' => 'Custom Product', 'sku' => 'C', 'unit_price' => 2000, 'qty' => 1],
                ['display_name' => 'Custom Product', 'sku' => 'C-2', 'unit_price' => 10000, 'qty' => 1],
            ],
            $rows
        );
    }

    public function testThreePricesOfOneSkuAreNumberedInOrderOfFirstAppearanceAndMergedPerPrice(): void
    {
        $items = [
            $this->item(['sku' => 'C', 'price' => '20', 'qty' => '1'], null),
            $this->item(['sku' => 'C', 'price' => '100', 'qty' => '1'], null),
            $this->item(['sku' => 'C', 'price' => '20', 'qty' => '2'], null),
            $this->item(['sku' => 'C', 'price' => '5', 'qty' => '1'], null),
            $this->item(['sku' => 'C', 'price' => '100', 'qty' => '3'], null),
        ];

        $rows = $this->builder()->build($this->quote($items, '465.0000'));

        $this->assertSame(
            [
                ['sku' => 'C', 'unit_price' => 2000, 'qty' => 3],
                ['sku' => 'C-2', 'unit_price' => 10000, 'qty' => 4],
                ['sku' => 'C-3', 'unit_price' => 500, 'qty' => 1],
            ],
            array_map(
                static fn (array $row): array => array_intersect_key($row, array_flip(['sku', 'unit_price', 'qty'])),
                $rows
            )
        );
    }

    public function testASuffixNeverEqualsAnotherSkuOfTheCart(): void
    {
        $items = [
            $this->item(['sku' => 'C-2', 'price' => '7', 'qty' => '1'], null),
            $this->item(['sku' => 'C', 'price' => '20', 'qty' => '1'], null),
            $this->item(['sku' => 'C', 'price' => '100', 'qty' => '1'], null),
            $this->item(['sku' => 'C', 'price' => '5', 'qty' => '1'], null),
            $this->item(['sku' => 'C-3', 'price' => '9', 'qty' => '1'], null),
        ];

        $rows = $this->builder()->build($this->quote($items, '141.0000'));

        $this->assertSame(['C-2', 'C', 'C-4', 'C-5', 'C-3'], array_column($rows, 'sku'));
        $this->assertSame([700, 2000, 10000, 500, 900], array_column($rows, 'unit_price'));
    }

    public function testMergedRowsKeepTheRoundingToleranceOfEachQuoteRow(): void
    {
        // Magento rounds each row total: 3 x 33.3333 = 100.00 per row, while the unit price is sent as 33.33.
        $items = [
            $this->item(['sku' => 'A', 'price' => '33.3333', 'qty' => '3'], null),
            $this->item(['sku' => 'A', 'price' => '33.3333', 'qty' => '3'], null),
        ];
        $this->logger->expects($this->once())
            ->method('info')
            ->with($this->anything(), ['difference_cents' => -2, 'rows' => 2]);

        $rows = $this->builder()->build($this->quote($items, '200.0000'));

        $this->assertCount(1, $rows);
        $this->assertSame(['sku' => 'A', 'unit_price' => 3333, 'qty' => 6], array_slice($rows[0], 1, 3));
    }

    public function testMergedRowsAreStillDeclinedBeyondTheTolerance(): void
    {
        $items = [
            $this->item(['sku' => 'A', 'price' => '10', 'qty' => '1'], null),
            $this->item(['sku' => 'A', 'price' => '10', 'qty' => '1'], null),
        ];

        $exception = $this->assertDeclined($this->quote($items, '20.0300'), AccountingException::REASON_ITEMS_MISMATCH);

        $this->assertSame(['difference_cents' => -3, 'rows' => 2], $exception->getDiagnostics());
    }

    public function testPlaceholderImagesAreNotSent(): void
    {
        $item = $this->item(['sku' => 'BED', 'price' => '10', 'qty' => '1'], 'no_selection');

        $rows = $this->builder()->build($this->quote([$item], '10'));

        $this->assertArrayNotHasKey('item_image_url', $rows[0]);
    }

    private function assertDeclined(Quote $quote, string $reason): AccountingException
    {
        try {
            $this->builder()->build($quote);
        } catch (AccountingException $exception) {
            $this->assertSame($reason, $exception->getReason());
            $this->assertSame('Affirm is not available for this cart.', $exception->getMessage());

            return $exception;
        }
        $this->fail('The cart was not declined.');
    }

    private function builder(): ItemsBuilder
    {
        return new ItemsBuilder($this->imageUrlBuilder, $this->util(), $this->priceCurrency(), $this->logger);
    }

    private function util(): Util
    {
        return new Util($this->createMock(Random::class));
    }

    private function priceCurrency(): PriceCurrencyInterface
    {
        $priceCurrency = $this->createMock(PriceCurrencyInterface::class);
        $priceCurrency->method('round')->willReturnCallback(
            static fn ($price): float => round((float) (string) $price, 2)
        );

        return $priceCurrency;
    }

    /**
     * @param list<Item> $items
     */
    private function quote(array $items, string $subtotal, bool $virtual = false): Quote
    {
        $address = $this->createMock(Address::class);
        $address->method('getData')->willReturnCallback(fn ($key = '') => $key === 'subtotal' ? $subtotal : null);
        $empty = $this->createMock(Address::class);

        $quote = $this->createMock(Quote::class);
        $quote->method('getAllVisibleItems')->willReturn($items);
        $quote->method('isVirtual')->willReturn($virtual);
        $quote->method('getShippingAddress')->willReturn($virtual ? $empty : $address);
        $quote->method('getBillingAddress')->willReturn($virtual ? $address : $empty);

        return $quote;
    }

    /**
     * @param array<string, mixed> $data
     * @param string|null $image product image and URL key; null for a product without image
     * @param list<Item> $children
     */
    private function item(
        array $data,
        ?string $image,
        array $children = [],
        bool $childrenCalculated = false,
        bool $urlFails = false
    ): Item {
        $product = $this->createMock(Product::class);
        $thumbnail = match ($image) {
            null => null,
            'no_selection' => 'no_selection',
            default => '/' . $image[0] . '/' . $image[1] . '/' . $image . '.jpg',
        };
        $product->method('getData')->willReturnCallback(fn ($key = '') => $key === 'thumbnail' ? $thumbnail : null);
        if ($urlFails) {
            $product->method('getProductUrl')->willThrowException(new \RuntimeException('no rewrite'));
        } else {
            $product->method('getProductUrl')->willReturn(
                $image === null ? '' : 'https://shop.example/' . $image . '.html'
            );
        }

        $item = $this->createMock(Item::class);
        $item->method('getData')->willReturnCallback(fn ($key = '') => $data[$key] ?? null);
        $item->method('getProduct')->willReturn($product);
        $item->method('getChildren')->willReturn($children);
        $item->method('isChildrenCalculated')->willReturn($childrenCalculated);

        return $item;
    }
}
