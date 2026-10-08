<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Test\Unit\Model\Attempt;

use Hyva\CheckoutAffirm\Model\Attempt\Snapshot;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Item;
use Magento\Quote\Model\Quote\Payment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SnapshotTest extends TestCase
{
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
     * @var Snapshot
     */
    private Snapshot $snapshot;

    protected function setUp(): void
    {
        $this->snapshot = new Snapshot();
    }

    public function testHashIsStableSha256(): void
    {
        $hash = $this->snapshot->hash($this->quote(self::cart()));

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
        $this->assertSame($hash, $this->snapshot->hash($this->quote(self::cart())));
    }

    /**
     * Every field Affirm relies on changes the hash.
     *
     * @return array<string, array{0: callable}>
     */
    public static function relevantChanges(): array
    {
        $changes = [
            'store' => static function (array &$cart): void {
                $cart['store_id'] = 2;
            },
            'payment method' => static function (array &$cart): void {
                $cart['payment'] = 'checkmo';
            },
            'item added' => static function (array &$cart): void {
                $cart['items'][] = self::item('EXTRA', '13', '1', '5', null);
            },
            'item removed' => static function (array &$cart): void {
                array_pop($cart['items']);
            },
        ];
        foreach ([
            'quote_currency_code' => 'CAD',
            'base_currency_code' => 'CAD',
            'grand_total' => '1325.51',
            'base_grand_total' => '1325.49',
            'subtotal' => '1201',
            'applied_rule_ids' => '1,3,4',
            'coupon_code' => 'OTHER',
            'customer_email' => 'other@example.com',
        ] as $field => $value) {
            $changes['quote ' . $field] = static function (array &$cart) use ($field, $value): void {
                $cart['quote'][$field] = $value;
            };
        }
        $shippingOnly = ['shipping_method' => 'tablerate_bestway', 'shipping_amount' => '51', 'tax_amount' => '85.6',
            'discount_amount' => '-11'];
        foreach (['shipping', 'billing'] as $type) {
            foreach (self::ADDRESS_FIELDS as $field) {
                $changes[$type . ' ' . $field] = static function (array &$cart) use ($type, $field): void {
                    $cart[$type][$field] = $field === 'region_id' ? '15' : 'changed';
                };
            }
        }
        foreach ($shippingOnly as $field => $value) {
            $changes['shipping ' . $field] = static function (array &$cart) use ($field, $value): void {
                $cart['shipping'][$field] = $value;
            };
        }
        foreach (['sku' => 'BED-K', 'product_id' => '99', 'qty' => '2', 'row_total' => '999.99',
            'parent_item_id' => '501'] as $field => $value) {
            $changes['item ' . $field] = static function (array &$cart) use ($field, $value): void {
                $cart['items'][1][$field] = $value;
            };
        }

        return array_map(static fn (callable $change): array => [$change], $changes);
    }

    #[DataProvider('relevantChanges')]
    public function testRelevantChangeAltersHash(callable $change): void
    {
        $cart = self::cart();
        $change($cart);

        $this->assertNotSame(
            $this->snapshot->hash($this->quote(self::cart())),
            $this->snapshot->hash($this->quote($cart))
        );
    }

    /**
     * Representation differences that must not change the hash.
     *
     * @return array<string, array{0: callable}>
     */
    public static function equivalentChanges(): array
    {
        return array_map(static fn (callable $change): array => [$change], [
            'float totals' => static function (array &$cart): void {
                $cart['quote']['grand_total'] = 1325.5;
                $cart['quote']['subtotal'] = 1200;
                $cart['shipping']['tax_amount'] = 85.5;
                $cart['items'][0]['qty'] = 1;
            },
            'item order' => static function (array &$cart): void {
                $cart['items'] = array_reverse($cart['items']);
            },
            'rule order' => static function (array &$cart): void {
                $cart['quote']['applied_rule_ids'] = '1, 3';
            },
            'email case and spaces' => static function (array &$cart): void {
                $cart['quote']['customer_email'] = ' SHOPPER@example.com ';
            },
            'street as array' => static function (array &$cart): void {
                $cart['shipping']['street'] = ['1 Main St', 'Suite 2'];
            },
            'unrelated data' => static function (array &$cart): void {
                $cart['quote']['updated_at'] = '2030-01-01 00:00:00';
                $cart['shipping']['company'] = 'ACME';
            },
            'billing totals on a physical cart' => static function (array &$cart): void {
                $cart['billing']['tax_amount'] = '999';
            },
        ]);
    }

    #[DataProvider('equivalentChanges')]
    public function testEquivalentChangeKeepsHash(callable $change): void
    {
        $cart = self::cart();
        $change($cart);

        $this->assertSame(
            $this->snapshot->hash($this->quote(self::cart())),
            $this->snapshot->hash($this->quote($cart))
        );
    }

    public function testVirtualCartUsesBillingTotals(): void
    {
        $cart = self::cart();
        $cart['virtual'] = true;
        $base = $this->snapshot->hash($this->quote($cart));

        $shippingTax = $cart;
        $shippingTax['shipping']['tax_amount'] = '1';
        $billingTax = $cart;
        $billingTax['billing']['tax_amount'] = '1';

        $this->assertSame($base, $this->snapshot->hash($this->quote($shippingTax)));
        $this->assertNotSame($base, $this->snapshot->hash($this->quote($billingTax)));
    }

    public function testCollectNormalisesValues(): void
    {
        $data = $this->snapshot->collect($this->quote(self::cart()));

        $this->assertSame('1325.5000', $data['grand_total']);
        $this->assertSame('1,3', $data['applied_rule_ids']);
        $this->assertSame('shopper@example.com', $data['customer_email']);
        $this->assertSame("1 Main St\nSuite 2", $data['shipping_address']['street']);
        $this->assertSame(14, $data['shipping_address']['region_id']);
        $this->assertSame('-10.0000', $data['discount_amount']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function cart(): array
    {
        $address = [
            'firstname' => 'Ada',
            'lastname' => 'Lovelace',
            'street' => "1 Main St\nSuite 2",
            'city' => 'Boise',
            'region' => 'Idaho',
            'region_id' => '14',
            'postcode' => '83702',
            'country_id' => 'US',
            'telephone' => '2085550100',
            'email' => 'shopper@example.com',
        ];

        return [
            'store_id' => 1,
            'virtual' => false,
            'payment' => 'affirm_gateway',
            'quote' => [
                'quote_currency_code' => 'USD',
                'base_currency_code' => 'USD',
                'grand_total' => '1325.5000',
                'base_grand_total' => '1325.5000',
                'subtotal' => '1200.0000',
                'applied_rule_ids' => '3,1',
                'coupon_code' => 'SAVE',
                'customer_email' => 'Shopper@Example.com',
            ],
            'shipping' => $address + [
                'shipping_method' => 'flatrate_flatrate',
                'shipping_amount' => '50.0000',
                'tax_amount' => '85.5000',
                'discount_amount' => '-10.0000',
            ],
            'billing' => $address + [
                'shipping_amount' => '0.0000',
                'tax_amount' => '0.0000',
                'discount_amount' => '0.0000',
            ],
            'items' => [
                self::item('BED-Q', '10', '1.0000', '1000.0000', null),
                self::item('BED-Q-OAK', '11', '1.0000', '0.0000', '500'),
                self::item('PILLOW', '12', '2.0000', '200.0000', null),
            ],
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private static function item(string $sku, string $productId, string $qty, string $rowTotal, ?string $parent): array
    {
        return [
            'sku' => $sku,
            'product_id' => $productId,
            'qty' => $qty,
            'row_total' => $rowTotal,
            'parent_item_id' => $parent,
        ];
    }

    /**
     * @param array<string, mixed> $cart
     */
    private function quote(array $cart): Quote
    {
        $items = [];
        foreach ($cart['items'] as $itemData) {
            $item = $this->createMock(Item::class);
            $item->method('getData')->willReturnCallback(fn ($key = '') => $itemData[$key] ?? null);
            $items[] = $item;
        }
        $payment = $this->createMock(Payment::class);
        $payment->method('getData')->willReturnCallback(fn ($key = '') => $key === 'method' ? $cart['payment'] : null);

        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(
                ['getStoreId', 'getData', 'getAllItems', 'getShippingAddress', 'getBillingAddress', 'getPayment',
                    'isVirtual']
            )
            ->getMock();
        $quote->method('getStoreId')->willReturn($cart['store_id']);
        $quote->method('getData')->willReturnCallback(fn ($key = '') => $cart['quote'][$key] ?? null);
        $quote->method('getAllItems')->willReturn($items);
        $quote->method('getShippingAddress')->willReturn($this->address($cart['shipping']));
        $quote->method('getBillingAddress')->willReturn($this->address($cart['billing']));
        $quote->method('getPayment')->willReturn($payment);
        $quote->method('isVirtual')->willReturn($cart['virtual']);

        return $quote;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function address(array $data): Address
    {
        $address = $this->createMock(Address::class);
        $address->method('getData')->willReturnCallback(fn ($key = '') => $data[$key] ?? null);

        return $address;
    }
}
