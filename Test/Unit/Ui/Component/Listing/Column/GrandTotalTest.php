<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Test\Unit\Ui\Component\Listing\Column;

use Hyva\CheckoutAffirm\Ui\Component\Listing\Column\GrandTotal;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use PHPUnit\Framework\TestCase;

class GrandTotalTest extends TestCase
{
    public function testFormatsCentsInTheAttemptCurrency(): void
    {
        $calls = [];
        $priceCurrency = $this->createMock(PriceCurrencyInterface::class);
        $priceCurrency->method('format')->willReturnCallback(
            function (float $amount, bool $container, int $precision, $scope, $currency) use (&$calls): string {
                $calls[] = [$amount, $container, $precision, $scope, $currency];

                return sprintf('%s %.2f', $currency ?? '-', $amount);
            }
        );
        $column = new GrandTotal(
            $this->createMock(ContextInterface::class),
            $this->createMock(UiComponentFactory::class),
            $priceCurrency,
            [],
            ['name' => 'grand_total_cents']
        );

        $result = $column->prepareDataSource(['data' => ['items' => [
            ['grand_total_cents' => '123456', 'currency' => 'USD'],
            ['grand_total_cents' => '5', 'currency' => 'CAD'],
            ['grand_total_cents' => '700', 'currency' => ''],
            ['grand_total_cents' => null, 'currency' => 'USD'],
        ]]]);

        $this->assertSame([
            [1234.56, false, 2, null, 'USD'],
            [0.05, false, 2, null, 'CAD'],
            [7.0, false, 2, null, null],
        ], $calls);
        $this->assertSame('USD 1234.56', $result['data']['items'][0]['grand_total_cents']);
        $this->assertSame('CAD 0.05', $result['data']['items'][1]['grand_total_cents']);
        $this->assertNull($result['data']['items'][3]['grand_total_cents']);
    }
}
