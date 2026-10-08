<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Test\Unit\Model\Checkout;

use Astound\Affirm\Gateway\Helper\Util;
use Hyva\CheckoutAffirm\Model\Checkout\AccountingException;
use Hyva\CheckoutAffirm\Model\Checkout\TotalsBuilder;
use Magento\Framework\Math\Random;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Address\Total\AbstractTotal;
use Magento\Quote\Model\Quote\TotalsCollectorList;
use Magento\SalesRule\Api\Data\DiscountDataInterface;
use Magento\SalesRule\Api\Data\RuleDiscountInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TotalsBuilderTest extends TestCase
{
    /**
     * Collectors of a stock Magento 2.4 store.
     */
    private const STOCK_COLLECTORS = [
        'subtotal',
        'tax_subtotal',
        'weee',
        'shipping',
        'tax_shipping',
        'discount',
        'shipping_discount',
        'tax',
        'weee_tax',
        'grand_total',
    ];

    /**
     * @var list<string>
     */
    private array $collectors = self::STOCK_COLLECTORS;

    public function testTaxExclusiveCart(): void
    {
        $totals = $this->builder()->build($this->quote($this->cart()));

        $this->assertSame(
            [
                'currency' => 'USD',
                'total' => 132025,
                'shipping_amount' => 5000,
                'tax_amount' => 7025,
                'discounts' => [],
            ],
            $totals
        );
    }

    public function testTaxInclusiveCartWithDiscountTaxCompensation(): void
    {
        // Catalog price 110.00 incl. 10% tax, 10% discount applied on the price incl. tax.
        $cart = $this->cart([
            'subtotal' => '100.0000',
            'shipping_amount' => '0.0000',
            'tax_amount' => '9.0000',
            'discount_amount' => '-11.0000',
            'discount_tax_compensation_amount' => '1.0000',
            'discount_description' => 'Ten percent',
            'grand_total' => '99.0000',
        ]);

        $totals = $this->builder()->build($this->quote($cart));

        $this->assertSame(9900, $totals['total']);
        $this->assertSame(900, $totals['tax_amount']);
        $this->assertSame(
            ['Ten percent' => ['discount_amount' => 1100, 'discount_display_name' => 'Ten percent']],
            $totals['discounts']
        );
    }

    public function testShippingDiscountIsPartOfTheDiscountAndBrokenDownPerRule(): void
    {
        $cart = $this->cart([
            'subtotal' => '200.0000',
            'shipping_amount' => '20.0000',
            'shipping_discount_amount' => '20.0000',
            'tax_amount' => '0.0000',
            'discount_amount' => '-40.0000',
            'discount_description' => 'Free shipping, SAVE20',
            'grand_total' => '180.0000',
        ]);
        $cart['discounts'] = [
            ['rule_id' => 3, 'label' => 'SAVE20', 'amount' => 20.0],
            ['rule_id' => 7, 'label' => 'Free shipping', 'amount' => 0.0],
            ['rule_id' => 7, 'label' => 'Free shipping', 'amount' => 20.0],
        ];

        $totals = $this->builder()->build($this->quote($cart));

        $this->assertSame(18000, $totals['total']);
        $this->assertSame(2000, $totals['shipping_amount']);
        $this->assertSame(
            [
                'SAVE20' => ['discount_amount' => 2000, 'discount_display_name' => 'SAVE20'],
                'Free shipping' => ['discount_amount' => 2000, 'discount_display_name' => 'Free shipping'],
            ],
            $totals['discounts']
        );
    }

    public function testRuleBreakdownThatDoesNotAddUpFallsBackToOneEntry(): void
    {
        $cart = $this->cart(
            ['discount_amount' => '-10.0000', 'discount_description' => 'Spring sale', 'grand_total' => '1310.2500']
        );
        $cart['discounts'] = [['rule_id' => 3, 'label' => 'Spring sale', 'amount' => 9.99]];

        $totals = $this->builder()->build($this->quote($cart));

        $this->assertSame(
            ['Spring sale' => ['discount_amount' => 1000, 'discount_display_name' => 'Spring sale']],
            $totals['discounts']
        );
    }

    public function testDiscountWithoutDescriptionAndNumericLabels(): void
    {
        $cart = $this->cart(['discount_amount' => '-10.0000', 'grand_total' => '1310.2500']);
        $cart['discounts'] = [['rule_id' => 3, 'label' => '2026', 'amount' => 10.0]];
        $totals = $this->builder()->build($this->quote($cart));
        $this->assertSame(
            ['Discount 2026' => ['discount_amount' => 1000, 'discount_display_name' => '2026']],
            $totals['discounts']
        );
        $this->assertSame(
            '{"Discount 2026":{"discount_amount":1000,"discount_display_name":"2026"}}',
            json_encode($totals['discounts'])
        );

        $cart['discounts'] = null;
        $totals = $this->builder()->build($this->quote($cart));
        $this->assertSame(
            ['Discount' => ['discount_amount' => 1000, 'discount_display_name' => 'Discount']],
            $totals['discounts']
        );
    }

    public function testUnsupportedCollectedSegmentIsDeclined(): void
    {
        $this->collectors[] = 'surcharge';
        $cart = $this->cart(['surcharge_amount' => '25.0000', 'grand_total' => '1345.2500']);

        $exception = $this->decline($cart, AccountingException::REASON_UNSUPPORTED_ADJUSTMENT);

        $this->assertSame(['codes' => ['surcharge']], $exception->getDiagnostics());
        $this->assertSame('Affirm is not available for this cart.', $exception->getMessage());
    }

    public function testDisplayOnlyFetchedSegmentIsNotDeclined(): void
    {
        // A collector that only displays savings: collect() adds nothing, fetch() returns non-zero segments.
        $this->collectors[] = 'sale_savings';
        $quote = $this->quote($this->cart());
        $quote->expects($this->never())->method('getTotals');

        $this->assertSame(132025, $this->builder()->build($quote)['total']);
    }

    public function testFixedProductTaxIsSupported(): void
    {
        $cart = $this->cart(['weee_amount' => '3.0000', 'grand_total' => '1323.2500']);

        $this->assertSame(132325, $this->builder()->build($this->quote($cart))['total']);
    }

    public function testAdjustmentAppliedToTheGrandTotalDirectlyIsDeclined(): void
    {
        // Gift cards and store credit lower the grand total without a collected amount.
        $cart = $this->cart(['grand_total' => '1220.2500']);

        $exception = $this->decline($cart, AccountingException::REASON_UNEXPLAINED_DIFFERENCE);

        $this->assertSame(['difference_cents' => -10000.0], $exception->getDiagnostics());
    }

    public function testOneCentDifferenceIsAccepted(): void
    {
        $cart = $this->cart(['grand_total' => '1320.2600']);

        $this->assertSame(132026, $this->builder()->build($this->quote($cart))['total']);
    }

    public function testQuoteAndAddressGrandTotalMustAgree(): void
    {
        $cart = $this->cart();
        $cart['quote']['grand_total'] = '1321.2500';

        $exception = $this->decline($cart, AccountingException::REASON_UNEXPLAINED_DIFFERENCE);

        $this->assertSame(['scope' => 'quote_address'], $exception->getDiagnostics());
    }

    public function testIgnoredCodeThatIsPartOfTheGrandTotal(): void
    {
        $this->collectors[] = 'handling_fee';
        $cart = $this->cart(['handling_fee_amount' => '5.0000', 'grand_total' => '1325.2500']);

        $this->assertSame(132525, $this->builder(['handling_fee'])->build($this->quote($cart))['total']);
        $this->decline($cart, AccountingException::REASON_UNSUPPORTED_ADJUSTMENT);
    }

    public function testIgnoredCodeThatIsInformational(): void
    {
        $this->collectors[] = 'savings_info';
        $cart = $this->cart(['savings_info_amount' => '40.0000']);

        $this->assertSame(132025, $this->builder([' savings_info ', ''])->build($this->quote($cart))['total']);
    }

    public function testIgnoredCodeDoesNotExcuseAnotherDifference(): void
    {
        $this->collectors[] = 'handling_fee';
        $cart = $this->cart(['handling_fee_amount' => '5.0000', 'grand_total' => '1330.2500']);

        $this->decline($cart, AccountingException::REASON_UNEXPLAINED_DIFFERENCE, ['handling_fee']);
    }

    public function testIgnoringABuiltInCodeDoesNotDoubleCountIt(): void
    {
        $cart = $this->cart([
            'shipping_discount_amount' => '20.0000',
            'discount_amount' => '-20.0000',
            'grand_total' => '1300.2500',
        ]);

        $this->assertSame(130025, $this->builder(['shipping_discount', 'tax'])->build($this->quote($cart))['total']);
    }

    public function testPositiveDiscountIsDeclined(): void
    {
        $cart = $this->cart(['discount_amount' => '10.0000', 'grand_total' => '1330.2500']);

        $exception = $this->decline($cart, AccountingException::REASON_UNSUPPORTED_ADJUSTMENT);

        $this->assertSame(['codes' => ['discount']], $exception->getDiagnostics());
    }

    public function testZeroTotalIsDeclined(): void
    {
        $cart = $this->cart([
            'subtotal' => '10.0000',
            'shipping_amount' => '0.0000',
            'tax_amount' => '0.0000',
            'discount_amount' => '-10.0000',
            'grand_total' => '0.0000',
        ]);

        $this->decline($cart, AccountingException::REASON_ZERO_TOTAL);
    }

    public function testCadIsSupported(): void
    {
        $cart = $this->cart();
        $cart['quote']['quote_currency_code'] = 'cad';
        $cart['quote']['base_currency_code'] = 'CAD';

        $this->assertSame('CAD', $this->builder()->build($this->quote($cart))['currency']);
    }

    /**
     * @return array<string, array{0: string|null, 1: string|null, 2: string}>
     */
    public static function currencies(): array
    {
        return [
            'euro' => ['EUR', 'EUR', AccountingException::REASON_UNSUPPORTED_CURRENCY],
            'missing' => [null, null, AccountingException::REASON_UNSUPPORTED_CURRENCY],
            'converted from USD' => ['CAD', 'USD', AccountingException::REASON_CURRENCY_MISMATCH],
            'converted from EUR' => ['USD', 'EUR', AccountingException::REASON_CURRENCY_MISMATCH],
        ];
    }

    #[DataProvider('currencies')]
    public function testCurrencyRules(?string $quoteCurrency, ?string $baseCurrency, string $reason): void
    {
        $cart = $this->cart();
        $cart['quote']['quote_currency_code'] = $quoteCurrency;
        $cart['quote']['base_currency_code'] = $baseCurrency;

        $exception = $this->decline($cart, $reason);

        $this->assertSame('Affirm is not available for the currency of this cart.', $exception->getMessage());
    }

    public function testMultishippingIsDeclined(): void
    {
        $cart = $this->cart();
        $cart['quote']['is_multi_shipping'] = '1';

        $this->decline($cart, AccountingException::REASON_MULTISHIPPING);
    }

    public function testVirtualCartUsesTheBillingAddress(): void
    {
        $cart = $this->cart();
        $cart['virtual'] = true;
        $cart['billing'] = $cart['shipping'];
        $cart['billing']['shipping_amount'] = '0.0000';
        $cart['billing']['grand_total'] = '1270.2500';
        $cart['quote']['grand_total'] = '1270.2500';
        $cart['shipping'] = ['grand_total' => '0.0000'];

        $totals = $this->builder()->build($this->quote($cart));

        $this->assertSame(127025, $totals['total']);
        $this->assertSame(0, $totals['shipping_amount']);
    }

    public function testTotalUsesTheAmountMagentoAuthorizes(): void
    {
        // Magento rounds the order total half up before Affirm's validator converts it with sprintf('%.2f').
        $cart = $this->cart([
            'subtotal' => '100.0000',
            'shipping_amount' => '0.0000',
            'tax_amount' => '0.0050',
            'discount_amount' => '0.0000',
            'grand_total' => '100.0050',
        ]);

        $totals = $this->builder()->build($this->quote($cart));

        $this->assertSame(10001, $totals['total']);
        $this->assertSame(1, $totals['tax_amount']);
        // @phpstan-ignore argument.type (Affirm documents int but formats any amount with sprintf('%.2f'))
        $this->assertSame(10000, $this->util()->formatToCents(100.005), 'Affirm\'s conversion alone truncates.');
    }

    /**
     * @return array<string, array{0: float, 1: int}>
     */
    public static function roundingCases(): array
    {
        return [
            '1.005' => [1.005, 101],
            '2.675' => [2.675, 268],
            '10.125' => [10.125, 1013],
            '0.285' => [0.285, 29],
            '99.995' => [99.995, 10000],
            '1.0049' => [1.0049, 100],
            'integer' => [1200.0, 120000],
        ];
    }

    #[DataProvider('roundingCases')]
    public function testToCents(float $amount, int $cents): void
    {
        $this->assertSame($cents, $this->builder()->toCents($amount));
    }

    /**
     * Stock tax-exclusive cart: 1200.00 subtotal, 50.00 shipping, 70.25 tax, no discount, 1320.25 total.
     *
     * @param array<string, string> $shipping overrides for the shipping address
     * @return array<string, mixed>
     */
    private function cart(array $shipping = []): array
    {
        $shipping += [
            'subtotal' => '1200.0000',
            'shipping_amount' => '50.0000',
            'tax_amount' => '70.2500',
            'discount_amount' => '0.0000',
            'discount_tax_compensation_amount' => '0.0000',
            'shipping_discount_tax_compensation_amount' => '0.0000',
            'grand_total' => '1320.2500',
        ];

        return [
            'virtual' => false,
            'quote' => [
                'quote_currency_code' => 'USD',
                'base_currency_code' => 'USD',
                'grand_total' => $shipping['grand_total'],
            ],
            'shipping' => $shipping,
            'billing' => ['grand_total' => '0.0000'],
            'discounts' => null,
        ];
    }

    /**
     * @param array<string, mixed> $cart
     * @param list<string> $ignored
     */
    private function decline(array $cart, string $reason, array $ignored = []): AccountingException
    {
        try {
            $this->builder($ignored)->build($this->quote($cart));
        } catch (AccountingException $exception) {
            $this->assertSame($reason, $exception->getReason());

            return $exception;
        }
        $this->fail('The cart was not declined.');
    }

    /**
     * @param list<string> $ignored
     */
    private function builder(array $ignored = []): TotalsBuilder
    {
        $collectors = [];
        foreach ($this->collectors as $code) {
            $collector = $this->createMock(AbstractTotal::class);
            $collector->method('getCode')->willReturn($code);
            $collectors[] = $collector;
        }
        $collectorList = $this->createMock(TotalsCollectorList::class);
        $collectorList->method('getCollectors')->with(2)->willReturn($collectors);

        $priceCurrency = $this->createMock(PriceCurrencyInterface::class);
        // Same as Magento\Directory\Model\PriceCurrency::round().
        $priceCurrency->method('round')->willReturnCallback(
            static fn ($price): float => round((float) (string) $price, 2)
        );

        return new TotalsBuilder($collectorList, $this->util(), $priceCurrency, $ignored);
    }

    private function util(): Util
    {
        return new Util($this->createMock(Random::class));
    }

    /**
     * @param array<string, mixed> $cart
     * @return Quote&\PHPUnit\Framework\MockObject\MockObject
     */
    private function quote(array $cart): Quote
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(
                ['getData', 'getStoreId', 'isVirtual', 'getShippingAddress', 'getBillingAddress', 'getTotals']
            )
            ->getMock();
        $quote->method('getData')->willReturnCallback(fn ($key = '') => $cart['quote'][$key] ?? null);
        $quote->method('getStoreId')->willReturn(2);
        $quote->method('isVirtual')->willReturn($cart['virtual']);
        $quote->method('getShippingAddress')->willReturn(
            $this->address($cart['shipping'], $cart['virtual'] ? null : $cart['discounts'])
        );
        $quote->method('getBillingAddress')->willReturn(
            $this->address($cart['billing'], $cart['virtual'] ? $cart['discounts'] : null)
        );

        return $quote;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<array{rule_id: int, label: string, amount: float}>|null $discounts
     */
    private function address(array $data, ?array $discounts): Address
    {
        $address = $this->createMock(Address::class);
        $address->method('getData')->willReturnCallback(fn ($key = '') => $data[$key] ?? null);
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
