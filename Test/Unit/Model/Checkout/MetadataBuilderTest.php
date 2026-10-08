<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Test\Unit\Model\Checkout;

use Hyva\CheckoutAffirm\Model\Checkout\MetadataBuilder;
use Hyva\CheckoutAffirm\Model\Version;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\Module\ResourceInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use PHPUnit\Framework\TestCase;

class MetadataBuilderTest extends TestCase
{
    public function testValuesMatchAffirmsOwnCheckout(): void
    {
        $metadata = $this->builder('4.0.11')->build($this->quote(false, 'Flat Rate - Fixed'), 'redirect');

        $this->assertSame(
            [
                'platform_type' => 'Magento 2',
                'platform_version' => '2.4.9 Community',
                'platform_affirm' => '4.0.11',
                'platform_integration' => 'hyva-checkout-affirm/' . Version::VERSION,
                'mode' => 'redirect',
                'shipping_type' => 'Flat Rate - Fixed',
            ],
            $metadata
        );
    }

    public function testVirtualCartHasNoShippingType(): void
    {
        $metadata = $this->builder('4.0.11')->build($this->quote(true, 'Flat Rate - Fixed'), 'modal');

        $this->assertArrayNotHasKey('shipping_type', $metadata);
        $this->assertSame('modal', $metadata['mode']);
    }

    public function testEmptyShippingDescriptionIsOmitted(): void
    {
        $metadata = $this->builder('4.0.11')->build($this->quote(false, ' '), 'inline');

        $this->assertArrayNotHasKey('shipping_type', $metadata);
    }

    public function testAffirmVersionFallsBackToModuleXml(): void
    {
        $metadata = $this->builder(false, ['name' => 'Astound_Affirm', 'setup_version' => '4.0.11'])
            ->build($this->quote(true, null), 'modal');

        $this->assertSame('4.0.11', $metadata['platform_affirm']);
    }

    public function testAffirmVersionLookupFailureFallsBackToModuleXml(): void
    {
        $metadata = $this->builder(new \RuntimeException('no setup_module table'), ['setup_version' => '4.0.10'])
            ->build($this->quote(true, null), 'modal');

        $this->assertSame('4.0.10', $metadata['platform_affirm']);
    }

    public function testUnknownAffirmVersionIsOmitted(): void
    {
        $metadata = $this->builder(false, null)->build($this->quote(true, null), 'modal');

        $this->assertArrayNotHasKey('platform_affirm', $metadata);
    }

    /**
     * @param string|false|\Throwable $dbVersion
     * @param array<string, string>|null $module
     */
    private function builder(string|false|\Throwable $dbVersion, ?array $module = null): MetadataBuilder
    {
        $productMetadata = $this->createMock(ProductMetadataInterface::class);
        $productMetadata->method('getName')->willReturn('Magento');
        $productMetadata->method('getVersion')->willReturn('2.4.9');
        $productMetadata->method('getEdition')->willReturn('Community');

        $moduleResource = $this->createMock(ResourceInterface::class);
        if ($dbVersion instanceof \Throwable) {
            $moduleResource->method('getDbVersion')->willThrowException($dbVersion);
        } else {
            $moduleResource->method('getDbVersion')->with('Astound_Affirm')->willReturn($dbVersion);
        }

        $moduleList = $this->createMock(ModuleListInterface::class);
        $moduleList->method('getOne')->with('Astound_Affirm')->willReturn($module);

        return new MetadataBuilder($productMetadata, $moduleResource, $moduleList);
    }

    private function quote(bool $virtual, ?string $shippingDescription): Quote
    {
        $address = $this->createMock(Address::class);
        $address->method('getData')->willReturnCallback(
            fn ($key = '') => $key === 'shipping_description' ? $shippingDescription : null
        );
        $quote = $this->createMock(Quote::class);
        $quote->method('isVirtual')->willReturn($virtual);
        $quote->method('getShippingAddress')->willReturn($address);

        return $quote;
    }
}
