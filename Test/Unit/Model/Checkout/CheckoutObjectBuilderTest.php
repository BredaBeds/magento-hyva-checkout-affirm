<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Test\Unit\Model\Checkout;

use Astound\Affirm\Gateway\Helper\Util;
use Astound\Affirm\Helper\FinancingProgram;
use Hyva\CheckoutAffirm\Model\Checkout\AccountingException;
use Hyva\CheckoutAffirm\Model\Checkout\CheckoutObjectBuilder;
use Hyva\CheckoutAffirm\Model\Checkout\ContactBuilder;
use Hyva\CheckoutAffirm\Model\Checkout\ItemsBuilder;
use Hyva\CheckoutAffirm\Model\Checkout\MetadataBuilder;
use Hyva\CheckoutAffirm\Model\Checkout\TotalsBuilder;
use Hyva\CheckoutAffirm\Model\Version;
use InvalidArgumentException;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Image\UrlBuilder;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\Math\Random;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\Module\ResourceInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\UrlInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Address\Total\AbstractTotal;
use Magento\Quote\Model\Quote\Item;
use Magento\Quote\Model\Quote\TotalsCollectorList;
use Magento\SalesRule\Api\Data\DiscountDataInterface;
use Magento\SalesRule\Api\Data\RuleDiscountInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * End-to-end fixtures through the real builders; only Magento and Affirm services are mocked.
 */
class CheckoutObjectBuilderTest extends TestCase
{
    private const ATTEMPT_ID = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    private const BASE_URL = 'https://shop.example/';

    private const STORE_ID = 3;

    /**
     * Key sets of the sandbox Read Checkout response (E2/E3 fixture): what Affirm accepts and echoes.
     */
    private const AFFIRM_TOP_LEVEL = [
        'currency', 'api_version', 'metadata', 'merchant_external_reference', 'order_id', 'financing_program_map',
        'items', 'merchant', 'checkout_flow_type', 'total', 'tax_amount', 'shipping_amount',
        'trade_in_amount_in_cents', 'baskets', 'meta', 'product_type', 'billing', 'shipping', 'checkout_type',
        'use_new_checkout_creation_flow', 'suppress_expiration_declination_messaging',
        'use_checkout_bff_decisioning', 'product_auth_expiration_seconds', 'config', 'product', 'checkout_status',
    ];
    private const AFFIRM_MERCHANT = [
        'name', 'public_api_key', 'user_confirmation_url', 'user_cancel_url', 'user_decline_url',
        'user_confirmation_url_action', 'use_vcn_app', 'use_vcn',
    ];
    private const AFFIRM_CONTACT = ['email', 'phone_number', 'address', 'name'];
    private const AFFIRM_ADDRESS = ['line1', 'city', 'state', 'zipcode', 'line2', 'country'];
    private const AFFIRM_NAME = ['full', 'first', 'last'];
    private const AFFIRM_ITEM = [
        'display_name', 'item_image_url', 'item_url', 'sku', 'unit_price', 'qty', 'categories', 'tax_amount',
        'shipping_amount', 'item_type',
    ];
    private const AFFIRM_METADATA = [
        'mode', 'platform_type', 'platform_version', 'platform_affirm', 'platform_integration', 'checkout_channel_type',
    ];

    /**
     * @var array<string, string>
     */
    private array $config = [];

    /**
     * @var list<string>
     */
    private array $collectors = [
        'subtotal', 'tax_subtotal', 'weee', 'shipping', 'tax_shipping', 'discount', 'shipping_discount', 'tax',
        'weee_tax', 'grand_total',
    ];

    /**
     * @var string|\Throwable
     */
    private string|\Throwable $financingProgram = 'promo_0apr_12';

    /**
     * @var string|\Throwable
     */
    private string|\Throwable $storeName = 'Example Store';

    /**
     * @var list<array{0: string, 1: array<string, mixed>}>
     */
    private array $warnings = [];

    protected function setUp(): void
    {
        $this->config = [
            'payment/affirm_gateway/mode' => 'sandbox',
            'payment/affirm_gateway/public_api_key_sandbox' => ' PUBLIC-SANDBOX-US ',
            'payment/affirm_gateway/public_api_key_sandbox_ca' => 'PUBLIC-SANDBOX-CA',
            'payment/affirm_gateway/public_api_key_production' => 'PUBLIC-LIVE-US',
            'payment/affirm_gateway/public_api_key_production_ca' => 'PUBLIC-LIVE-CA',
        ];
    }

    public function testRedirectCheckoutObject(): void
    {
        $checkout = $this->builder()->build($this->quote($this->cart()), 'Redirect', self::ATTEMPT_ID);

        $contact = [
            'name' => ['first' => 'Ada', 'last' => 'Lovelace'],
            'address' => [
                'line1' => '1 Main St',
                'line2' => 'Suite 2',
                'city' => 'Boise',
                'state' => 'ID',
                'zipcode' => '83702',
                'country' => 'USA',
            ],
            'phone_number' => '2085550100',
            'email' => 'ada@example.com',
        ];
        $this->assertSame(
            [
                'merchant' => [
                    'public_api_key' => 'PUBLIC-SANDBOX-US',
                    'user_confirmation_url' => self::BASE_URL . 'hyva_affirm/checkout/confirm/attempt/'
                        . self::ATTEMPT_ID . '/',
                    'user_cancel_url' => self::BASE_URL . 'hyva_affirm/checkout/cancel/attempt/'
                        . self::ATTEMPT_ID . '/',
                    'user_decline_url' => self::BASE_URL . 'hyva_affirm/checkout/cancel/attempt/' . self::ATTEMPT_ID
                        . '/reason/declined/',
                    'user_confirmation_url_action' => 'GET',
                    'name' => 'Example Store',
                ],
                'shipping' => $contact,
                'billing' => $contact,
                'items' => [
                    [
                        'display_name' => 'Queen bed',
                        'sku' => 'BED-Q',
                        'unit_price' => 120000,
                        'qty' => 1,
                        'item_image_url' => self::BASE_URL . 'media/q/u/queen-bed.jpg',
                        'item_url' => self::BASE_URL . 'queen-bed.html',
                    ],
                ],
                'metadata' => [
                    'platform_type' => 'Magento 2',
                    'platform_version' => '2.4.9 Community',
                    'platform_affirm' => '4.0.11',
                    'platform_integration' => 'hyva-checkout-affirm/' . Version::get(),
                    'mode' => 'redirect',
                    'shipping_type' => 'Flat Rate - Fixed',
                ],
                'order_id' => '000000123',
                'shipping_amount' => 5000,
                'tax_amount' => 7025,
                'total' => 132025,
                'currency' => 'USD',
                'financing_program' => 'promo_0apr_12',
            ],
            $checkout
        );
        $this->assertSame([], $this->warnings);
    }

    public function testModalModeLeavesTheConfirmationActionToAffirm(): void
    {
        $checkout = $this->builder()->build($this->quote($this->cart()), 'modal', self::ATTEMPT_ID);

        $this->assertArrayNotHasKey('user_confirmation_url_action', $checkout['merchant']);
        $this->assertStringContainsString(
            '/attempt/' . self::ATTEMPT_ID . '/',
            $checkout['merchant']['user_cancel_url']
        );
        $this->assertSame('modal', $checkout['metadata']['mode']);
    }

    public function testInlineModeHasNoAttempt(): void
    {
        $checkout = $this->builder()->build($this->quote($this->cart()), 'inline', 'ignored-for-inline');

        $this->assertSame(
            self::BASE_URL . 'hyva_affirm/checkout/confirm/',
            $checkout['merchant']['user_confirmation_url']
        );
        $this->assertSame(
            self::BASE_URL . 'hyva_affirm/checkout/cancel/reason/declined/',
            $checkout['merchant']['user_decline_url']
        );
        $this->assertSame('inline', $checkout['metadata']['mode']);
        $this->assertSame(132025, $checkout['total']);
    }

    public function testInvalidModeIsAProgrammingError(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->builder()->build($this->quote($this->cart()), 'popup', self::ATTEMPT_ID);
    }

    public function testMalformedAttemptIdIsAProgrammingError(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A valid checkout attempt id is required for this mode.');

        $this->builder()->build($this->quote($this->cart()), 'redirect', '../../admin');
    }

    public function testConfigurableCart(): void
    {
        $cart = $this->cart();
        $cart['items'] = [
            $this->line('SHIRT', 'Shirt', '1200.0000', '1', 'shirt', 'configurable', [
                $this->line('SHIRT-RED-M', 'Shirt', '0.0000', '1', 'shirt-red'),
            ]),
        ];

        $items = $this->builder()->build($this->quote($cart), 'modal', self::ATTEMPT_ID)['items'];

        $this->assertCount(1, $items);
        $this->assertSame('SHIRT-RED-M', $items[0]['sku']);
        $this->assertSame(self::BASE_URL . 'media/s/h/shirt-red.jpg', $items[0]['item_image_url']);
        $this->assertSame(self::BASE_URL . 'shirt.html', $items[0]['item_url']);
    }

    public function testFixedPriceBundleCart(): void
    {
        $cart = $this->cart();
        $cart['items'] = [
            $this->line('KIT', 'Bed kit', '600.0000', '2', 'kit', 'bundle', [
                $this->line('FRAME', 'Frame', '0.0000', '1', 'frame'),
                $this->line('SLATS', 'Slats', '0.0000', '4', 'slats'),
            ]),
        ];

        $items = $this->builder()->build($this->quote($cart), 'modal', self::ATTEMPT_ID)['items'];

        $this->assertSame([['KIT', 60000, 2]], $this->summary($items));
    }

    public function testDynamicBundleCartWithParentQuantityAboveOne(): void
    {
        $cart = $this->cart();
        $cart['items'] = [
            $this->line('KIT', 'Bed kit', '600.0000', '2', 'kit', 'bundle', [
                $this->line('FRAME', 'Frame', '450.0000', '1', 'frame'),
                $this->line('SLATS', 'Slats', '50.0000', '3', null),
            ], true),
        ];

        $items = $this->builder()->build($this->quote($cart), 'modal', self::ATTEMPT_ID)['items'];

        $this->assertSame([['FRAME', 45000, 2], ['SLATS', 5000, 6]], $this->summary($items));
        $this->assertSame(self::BASE_URL . 'kit.html', $items[1]['item_url']);
        $this->assertSame(self::BASE_URL . 'media/k/i/kit.jpg', $items[1]['item_image_url']);
    }

    public function testVirtualCart(): void
    {
        $cart = $this->cart();
        $cart['virtual'] = true;
        $cart['items'] = [$this->line('WARRANTY', 'Extended warranty', '1200.0000', '1', null, 'virtual')];
        $cart['billing'] = $cart['shipping'];
        $cart['billing']['shipping_amount'] = '0.0000';
        $cart['billing']['grand_total'] = '1270.2500';
        unset($cart['billing']['shipping_method'], $cart['billing']['shipping_description']);
        $cart['shipping'] = ['grand_total' => '0.0000'];
        $cart['quote']['grand_total'] = '1270.2500';

        $checkout = $this->builder()->build($this->quote($cart), 'redirect', self::ATTEMPT_ID);

        $this->assertSame($checkout['billing'], $checkout['shipping']);
        $this->assertSame(0, $checkout['shipping_amount']);
        $this->assertSame(127025, $checkout['total']);
        $this->assertArrayNotHasKey('shipping_type', $checkout['metadata']);
    }

    public function testMixedCart(): void
    {
        $cart = $this->cart();
        $cart['items'] = [
            $this->line('BED-Q', 'Queen bed', '1100.0000', '1', 'queen-bed'),
            $this->line('WARRANTY', 'Extended warranty', '50.0000', '2', null, 'virtual'),
        ];

        $items = $this->builder()->build($this->quote($cart), 'modal', self::ATTEMPT_ID)['items'];

        $this->assertSame([['BED-Q', 110000, 1], ['WARRANTY', 5000, 2]], $this->summary($items));
    }

    public function testDiscountIncludingAShippingDiscount(): void
    {
        $cart = $this->cart();
        $cart['shipping'] = array_merge($cart['shipping'], [
            'discount_amount' => '-170.0000',
            'shipping_discount_amount' => '50.0000',
            'discount_description' => 'Free shipping, SAVE10',
            'grand_total' => '1150.2500',
        ]);
        $cart['quote']['grand_total'] = '1150.2500';
        $cart['discounts'] = [
            ['rule_id' => 4, 'label' => 'SAVE10', 'amount' => 120.0],
            ['rule_id' => 9, 'label' => 'Free shipping', 'amount' => 50.0],
        ];

        $checkout = $this->builder()->build($this->quote($cart), 'redirect', self::ATTEMPT_ID);

        $this->assertSame(
            [
                'SAVE10' => ['discount_amount' => 12000, 'discount_display_name' => 'SAVE10'],
                'Free shipping' => ['discount_amount' => 5000, 'discount_display_name' => 'Free shipping'],
            ],
            $checkout['discounts']
        );
        $this->assertSame(5000, $checkout['shipping_amount']);
        $this->assertSame(115025, $checkout['total']);
        $this->assertSame(['merchant', 'shipping', 'billing', 'items', 'discounts', 'metadata'], array_slice(
            array_keys($checkout),
            0,
            6
        ));
    }

    public function testTaxInclusiveCart(): void
    {
        // 1210.00 incl. 10% tax, 10% discount on the price incl. tax, shipping 50.00 excl. tax and untaxed.
        $cart = $this->cart();
        $cart['items'] = [$this->line('BED-Q', 'Queen bed', '1100.0000', '1', null)];
        $cart['shipping'] = array_merge($cart['shipping'], [
            'subtotal' => '1100.0000',
            'tax_amount' => '99.0000',
            'discount_amount' => '-121.0000',
            'discount_tax_compensation_amount' => '11.0000',
            'grand_total' => '1139.0000',
        ]);
        $cart['quote']['grand_total'] = '1139.0000';

        $checkout = $this->builder()->build($this->quote($cart), 'modal', self::ATTEMPT_ID);

        $this->assertSame(113900, $checkout['total']);
        $this->assertSame(9900, $checkout['tax_amount']);
        $this->assertSame(12100, $checkout['discounts']['Discount']['discount_amount']);
        $this->assertSame(110000, $checkout['items'][0]['unit_price']);
    }

    public function testCanadianCart(): void
    {
        $cart = $this->cart();
        $cart['quote']['quote_currency_code'] = 'CAD';
        $cart['quote']['base_currency_code'] = 'CAD';
        $cart['shipping']['country_id'] = 'CA';
        $cart['billing']['country_id'] = 'CA';

        $checkout = $this->builder()->build($this->quote($cart), 'redirect', self::ATTEMPT_ID);

        $this->assertSame('PUBLIC-SANDBOX-CA', $checkout['merchant']['public_api_key']);
        $this->assertSame('CAD', $checkout['currency']);
        $this->assertSame('CAN', $checkout['billing']['address']['country']);
    }

    public function testProductionKeys(): void
    {
        $this->config['payment/affirm_gateway/mode'] = 'production';

        $checkout = $this->builder()->build($this->quote($this->cart()), 'redirect', self::ATTEMPT_ID);

        $this->assertSame('PUBLIC-LIVE-US', $checkout['merchant']['public_api_key']);
    }

    public function testMissingPublicKeyIsDeclined(): void
    {
        $this->config['payment/affirm_gateway/public_api_key_sandbox'] = '';

        $this->assertDeclined($this->cart(), AccountingException::REASON_NOT_CONFIGURED);
    }

    public function testGuestAndCustomerEmails(): void
    {
        $guest = $this->cart();
        $guest['quote']['customer_email'] = null;
        $guest['billing']['email'] = 'guest@example.com';
        $customer = $this->cart();
        $customer['quote']['customer_email'] = 'customer@example.com';

        $guestCheckout = $this->builder()->build($this->quote($guest), 'modal', self::ATTEMPT_ID);
        $customerCheckout = $this->builder()->build($this->quote($customer), 'modal', self::ATTEMPT_ID);

        $this->assertSame('guest@example.com', $guestCheckout['shipping']['email']);
        $this->assertSame('customer@example.com', $customerCheckout['billing']['email']);
    }

    public function testHalfCentRounding(): void
    {
        $cart = $this->cart();
        $cart['items'] = [$this->line('PILLOW', 'Pillow', '10.005', '1', null)];
        $cart['shipping'] = array_merge($cart['shipping'], [
            'subtotal' => '10.0050',
            'shipping_amount' => '0.0000',
            'tax_amount' => '0.7250',
            'grand_total' => '10.7300',
        ]);
        $cart['quote']['grand_total'] = '10.7300';

        $checkout = $this->builder()->build($this->quote($cart), 'modal', self::ATTEMPT_ID);

        $this->assertSame(1001, $checkout['items'][0]['unit_price']);
        $this->assertSame(73, $checkout['tax_amount']);
        $this->assertSame(1073, $checkout['total']);
    }

    public function testUnsupportedSegmentIsDeclinedAndLogged(): void
    {
        $this->collectors[] = 'giftwrapping';
        $cart = $this->cart();
        $cart['shipping']['giftwrapping_amount'] = '5.0000';
        $cart['shipping']['grand_total'] = '1325.2500';
        $cart['quote']['grand_total'] = '1325.2500';

        $exception = $this->assertDeclined($cart, AccountingException::REASON_UNSUPPORTED_ADJUSTMENT);

        $this->assertSame('Affirm is not available for this cart.', $exception->getMessage());
        $this->assertSame(
            [['Affirm is not offered for the cart.', [
                'reason' => 'unsupported_adjustment',
                'codes' => ['giftwrapping'],
                'mode' => 'redirect',
            ]]],
            $this->warnings
        );
    }

    public function testDisplayOnlySegmentIsNotDeclined(): void
    {
        $this->collectors[] = 'sale_savings';
        $quote = $this->quote($this->cart());
        $quote->expects($this->never())->method('getTotals');

        $this->assertSame(132025, $this->builder()->build($quote, 'redirect', self::ATTEMPT_ID)['total']);
    }

    public function testCurrencyMismatchIsDeclined(): void
    {
        $cart = $this->cart();
        $cart['quote']['quote_currency_code'] = 'CAD';

        $exception = $this->assertDeclined($cart, AccountingException::REASON_CURRENCY_MISMATCH);

        $this->assertSame('Affirm is not available for the currency of this cart.', $exception->getMessage());
    }

    public function testNonIntegerQuantityIsDeclined(): void
    {
        $cart = $this->cart();
        $cart['items'] = [$this->line('FABRIC', 'Fabric', '800.0000', '1.5', null)];

        $this->assertDeclined($cart, AccountingException::REASON_NON_INTEGER_QTY);
    }

    public function testOrderIdMustBeReserved(): void
    {
        $cart = $this->cart();
        $cart['quote']['reserved_order_id'] = ' ';

        $this->assertDeclined($cart, AccountingException::REASON_ORDER_NOT_RESERVED);
    }

    public function testEmptyFinancingProgramIsOmitted(): void
    {
        $this->financingProgram = '';

        $checkout = $this->builder()->build($this->quote($this->cart()), 'redirect', self::ATTEMPT_ID);

        $this->assertArrayNotHasKey('financing_program', $checkout);
    }

    public function testFinancingProgramFailureDeclinesRatherThanChangingTheTerms(): void
    {
        $this->financingProgram = new \RuntimeException('customer session unavailable');

        $exception = $this->assertDeclined($this->cart(), AccountingException::REASON_FINANCING_PROGRAM);

        $this->assertSame(['error' => \RuntimeException::class], $exception->getDiagnostics());
    }

    public function testStoreNameIsOptional(): void
    {
        $this->storeName = new \RuntimeException('store not found');

        $checkout = $this->builder()->build($this->quote($this->cart()), 'redirect', self::ATTEMPT_ID);

        $this->assertArrayNotHasKey('name', $checkout['merchant']);
    }

    public function testDeclineLogHoldsNoPersonalDataOrAttemptId(): void
    {
        $cart = $this->cart();
        $cart['shipping']['city'] = '';

        $this->assertDeclined($cart, AccountingException::REASON_INCOMPLETE_ADDRESS);

        $logged = json_encode($this->warnings, JSON_THROW_ON_ERROR);
        $personal = ['Ada', 'Lovelace', 'ada@example.com', '2085550100', '83702', self::ATTEMPT_ID, '000000123'];
        foreach ($personal as $value) {
            $this->assertStringNotContainsString($value, $logged);
        }
        $this->assertSame(
            ['reason' => 'incomplete_address', 'address' => 'shipping', 'fields' => ['city'], 'mode' => 'redirect'],
            $this->warnings[0][1]
        );
    }

    public function testBuildingWritesNothing(): void
    {
        $quote = $this->quote($this->cart());
        foreach (['reserveOrderId', 'collectTotals', 'save', 'setData', 'setReservedOrderId'] as $method) {
            $quote->expects($this->never())->method($method);
        }

        $this->builder()->build($quote, 'redirect', self::ATTEMPT_ID);
    }

    public function testShapeMatchesAffirmsReadCheckoutFixture(): void
    {
        $cart = $this->cart();
        $cart['shipping']['discount_amount'] = '-20.0000';
        $cart['shipping']['grand_total'] = '1300.2500';
        $cart['quote']['grand_total'] = '1300.2500';

        $checkout = $this->builder()->build($this->quote($cart), 'redirect', self::ATTEMPT_ID);

        $this->assertSubset(
            self::AFFIRM_TOP_LEVEL,
            array_diff(array_keys($checkout), ['discounts', 'financing_program'])
        );
        $this->assertSubset(self::AFFIRM_MERCHANT, array_keys($checkout['merchant']));
        foreach (['shipping', 'billing'] as $type) {
            $this->assertSubset(self::AFFIRM_CONTACT, array_keys($checkout[$type]));
            $this->assertEqualsCanonicalizing(self::AFFIRM_ADDRESS, array_keys($checkout[$type]['address']));
            $this->assertSubset(self::AFFIRM_NAME, array_keys($checkout[$type]['name']));
        }
        foreach ($checkout['items'] as $item) {
            $this->assertSubset(self::AFFIRM_ITEM, array_keys($item));
            $this->assertIsInt($item['unit_price']);
            $this->assertIsInt($item['qty']);
        }
        // Affirm's Luma checkout also sends shipping_type; the sandbox check did not.
        $this->assertSubset(self::AFFIRM_METADATA, array_diff(array_keys($checkout['metadata']), ['shipping_type']));
        foreach (['total', 'tax_amount', 'shipping_amount'] as $field) {
            $this->assertIsInt($checkout[$field]);
        }

        $json = json_decode(json_encode($checkout, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        $this->assertIsObject($json->discounts);
        $this->assertIsArray($json->items);
    }

    /**
     * @param list<string> $allowed
     * @param array<int, string> $keys
     */
    private function assertSubset(array $allowed, array $keys): void
    {
        $this->assertSame([], array_values(array_diff($keys, $allowed)));
    }

    /**
     * @param array<string, mixed> $cart
     */
    private function assertDeclined(array $cart, string $reason): AccountingException
    {
        try {
            $this->builder()->build($this->quote($cart), 'redirect', self::ATTEMPT_ID);
        } catch (AccountingException $exception) {
            $this->assertSame($reason, $exception->getReason());
            $this->assertCount(1, $this->warnings);
            $this->assertSame($reason, $this->warnings[0][1]['reason']);

            return $exception;
        }
        $this->fail('The cart was not declined.');
    }

    /**
     * @param list<array<string, int|string>> $items
     * @return list<array{0: int|string, 1: int|string, 2: int|string}>
     */
    private function summary(array $items): array
    {
        return array_map(static fn (array $item): array => [$item['sku'], $item['unit_price'], $item['qty']], $items);
    }

    /**
     * Physical guest cart in USD: one bed, flat-rate shipping, sales tax, no discount.
     *
     * @return array<string, mixed>
     */
    private function cart(): array
    {
        $contact = [
            'firstname' => 'Ada',
            'lastname' => 'Lovelace',
            'street' => "1 Main St\nSuite 2",
            'city' => 'Boise',
            'region' => 'Idaho',
            'region_id' => '14',
            'postcode' => '83702',
            'country_id' => 'US',
            'telephone' => '2085550100',
            'email' => 'ada@example.com',
        ];

        return [
            'virtual' => false,
            'quote' => [
                'reserved_order_id' => '000000123',
                'quote_currency_code' => 'USD',
                'base_currency_code' => 'USD',
                'customer_email' => 'ada@example.com',
                'grand_total' => '1320.2500',
            ],
            'shipping' => $contact + [
                'shipping_method' => 'flatrate_flatrate',
                'shipping_description' => 'Flat Rate - Fixed',
                'subtotal' => '1200.0000',
                'shipping_amount' => '50.0000',
                'tax_amount' => '70.2500',
                'discount_amount' => '0.0000',
                'grand_total' => '1320.2500',
            ],
            'billing' => $contact + ['grand_total' => '0.0000'],
            'items' => [$this->line('BED-Q', 'Queen bed', '1200.0000', '1.0000', 'queen-bed')],
            'discounts' => null,
        ];
    }

    /**
     * Quote item spec.
     *
     * @param list<array<string, mixed>> $children
     * @return array<string, mixed>
     */
    private function line(
        string $sku,
        string $name,
        string $price,
        string $qty,
        ?string $image,
        string $type = 'simple',
        array $children = [],
        bool $childrenCalculated = false
    ): array {
        return [
            'data' => ['sku' => $sku, 'name' => $name, 'price' => $price, 'qty' => $qty, 'product_type' => $type],
            'image' => $image,
            'children' => $children,
            'calculated' => $childrenCalculated,
        ];
    }

    private function builder(): CheckoutObjectBuilder
    {
        $util = new Util($this->createMock(Random::class));
        $priceCurrency = $this->createMock(PriceCurrencyInterface::class);
        $priceCurrency->method('round')->willReturnCallback(
            static fn ($price): float => round((float) (string) $price, 2)
        );
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function (string $message, array $context = []): void {
            $this->warnings[] = [$message, $context];
        });

        $imageUrlBuilder = $this->createMock(UrlBuilder::class);
        $imageUrlBuilder->method('getUrl')->willReturnCallback(
            static fn (string $file): string => self::BASE_URL . 'media' . $file
        );

        $collectors = [];
        foreach ($this->collectors as $code) {
            $collector = $this->createMock(AbstractTotal::class);
            $collector->method('getCode')->willReturn($code);
            $collectors[] = $collector;
        }
        $collectorList = $this->createMock(TotalsCollectorList::class);
        $collectorList->method('getCollectors')->with(self::STORE_ID)->willReturn($collectors);

        $productMetadata = $this->createMock(ProductMetadataInterface::class);
        $productMetadata->method('getName')->willReturn('Magento');
        $productMetadata->method('getVersion')->willReturn('2.4.9');
        $productMetadata->method('getEdition')->willReturn('Community');
        $moduleResource = $this->createMock(ResourceInterface::class);
        $moduleResource->method('getDbVersion')->willReturn('4.0.11');

        $financingProgram = $this->createMock(FinancingProgram::class);
        if ($this->financingProgram instanceof \Throwable) {
            $financingProgram->method('getFinancingProgramValue')->willThrowException($this->financingProgram);
        } else {
            $financingProgram->method('getFinancingProgramValue')->willReturn($this->financingProgram);
        }

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            function (string $path, string $scope, $storeId): ?string {
                $this->assertSame(ScopeInterface::SCOPE_STORE, $scope);
                $this->assertSame(self::STORE_ID, $storeId);

                return $this->config[$path] ?? null;
            }
        );

        $urlBuilder = $this->createMock(UrlInterface::class);
        $urlBuilder->method('getUrl')->willReturnCallback(function (string $route, array $params): string {
            $this->assertTrue($params['_secure']);
            $this->assertTrue($params['_nosid']);
            $path = '';
            foreach ($params as $key => $value) {
                $path .= str_starts_with($key, '_') ? '' : $key . '/' . $value . '/';
            }

            return self::BASE_URL . $route . '/' . $path;
        });

        return new CheckoutObjectBuilder(
            new ItemsBuilder($imageUrlBuilder, $util, $priceCurrency, $logger),
            new ContactBuilder(),
            new TotalsBuilder($collectorList, $util, $priceCurrency),
            new MetadataBuilder($productMetadata, $moduleResource, $this->createMock(ModuleListInterface::class)),
            $financingProgram,
            $scopeConfig,
            $urlBuilder,
            $logger
        );
    }

    /**
     * @param array<string, mixed> $cart
     * @return Quote&MockObject
     */
    private function quote(array $cart): Quote
    {
        $store = $this->createMock(Store::class);
        if ($this->storeName instanceof \Throwable) {
            $store->method('getFrontendName')->willThrowException($this->storeName);
        } else {
            $store->method('getFrontendName')->willReturn($this->storeName);
        }

        $quote = $this->createMock(Quote::class);
        $quote->method('getData')->willReturnCallback(fn ($key = '') => $cart['quote'][$key] ?? null);
        $quote->method('getStoreId')->willReturn(self::STORE_ID);
        $quote->method('getStore')->willReturn($store);
        $quote->method('isVirtual')->willReturn($cart['virtual']);
        $quote->method('getAllVisibleItems')->willReturn(array_map([$this, 'item'], $cart['items']));
        $quote->method('getShippingAddress')->willReturn(
            $this->address($cart['shipping'], $cart['virtual'] ? null : $cart['discounts'])
        );
        $quote->method('getBillingAddress')->willReturn(
            $this->address($cart['billing'], $cart['virtual'] ? $cart['discounts'] : null)
        );

        return $quote;
    }

    /**
     * @param array<string, mixed> $spec
     */
    private function item(array $spec): Item
    {
        $image = $spec['image'];
        $product = $this->createMock(Product::class);
        $product->method('getData')->willReturnCallback(
            fn ($key = '') => $key === 'thumbnail' && $image !== null
                ? '/' . $image[0] . '/' . $image[1] . '/' . $image . '.jpg'
                : null
        );
        $product->method('getProductUrl')->willReturn($image === null ? '' : self::BASE_URL . $image . '.html');

        $item = $this->createMock(Item::class);
        $item->method('getData')->willReturnCallback(fn ($key = '') => $spec['data'][$key] ?? null);
        $item->method('getProduct')->willReturn($product);
        $item->method('getChildren')->willReturn(array_map([$this, 'item'], $spec['children']));
        $item->method('isChildrenCalculated')->willReturn($spec['calculated']);

        return $item;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<array{rule_id: int, label: string, amount: float}>|null $discounts
     */
    private function address(array $data, ?array $discounts): Address
    {
        $address = $this->createMock(Address::class);
        $address->method('getData')->willReturnCallback(fn ($key = '') => $data[$key] ?? null);
        $address->method('getRegionCode')->willReturn(isset($data['region_id']) ? 'ID' : null);
        if ($discounts === null) {
            return $address;
        }

        $breakdown = [];
        foreach ($discounts as $discount) {
            $discountData = $this->createMock(DiscountDataInterface::class);
            $discountData->method('getAmount')->willReturn($discount['amount']);
            $ruleDiscount = $this->createMock(RuleDiscountInterface::class);
            $ruleDiscount->method('getRuleID')->willReturn($discount['rule_id']);
            $ruleDiscount->method('getRuleLabel')->willReturn($discount['label']);
            $ruleDiscount->method('getDiscountData')->willReturn($discountData);
            $breakdown[] = $ruleDiscount;
        }
        $address->method('getExtensionAttributes')->willReturn(new class($breakdown) {
            /**
             * @param list<RuleDiscountInterface> $discounts
             */
            public function __construct(private readonly array $discounts)
            {
            }

            /**
             * @return list<RuleDiscountInterface>
             */
            public function getDiscounts(): array
            {
                return $this->discounts;
            }
        });

        return $address;
    }
}
