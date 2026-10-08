<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Test\Unit\ViewModel;

use Astound\Affirm\Model\Config as AffirmConfig;
use Hyva\CheckoutAffirm\ViewModel\Sdk;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class SdkTest extends TestCase
{
    private const SCRIPT = 'https://cdn1-sandbox.affirm.com/js/v2/affirm.js';

    /** @var AffirmConfig&MockObject */
    private AffirmConfig $config;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->config = $this->createMock(AffirmConfig::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function configure(bool $active = true, string $currency = 'USD', string $key = 'PUBLIC_KEY'): void
    {
        $this->config->method('getConfigData')->with('active')->willReturn($active ? '1' : '0');
        $this->config->method('getCurrency')->willReturn($currency);
        $this->config->method('getPublicApiKey')->willReturn($key);
        $this->config->method('getScript')->willReturn(self::SCRIPT);
        $this->config->method('getLocale')->willReturn($currency === 'CAD' ? 'en_CA' : 'en_US');
        $this->config->method('getCountryCode')->willReturn($currency === 'CAD' ? 'CAN' : 'USA');
    }

    public function testLoaderConfigIsAffirmsDocumentedConfig(): void
    {
        $this->configure(true, 'USD', ' PUBLIC_KEY ');
        $sdk = new Sdk($this->config, $this->logger);

        $this->assertTrue($sdk->isConfigured());
        $this->assertSame([
            'public_api_key' => 'PUBLIC_KEY',
            'script' => self::SCRIPT,
            'locale' => 'en_US',
            'country_code' => 'USA',
        ], $sdk->getLoaderConfig());
    }

    public function testCanadianStoresUseTheCanadianSettings(): void
    {
        $this->configure(true, 'CAD');
        $sdk = new Sdk($this->config, $this->logger);

        $this->assertTrue($sdk->isConfigured());
        $this->assertSame('CAN', $sdk->getLoaderConfig()['country_code']);
        $this->assertSame('en_CA', $sdk->getLoaderConfig()['locale']);
    }

    public function testInactiveAffirmIsNotConfigured(): void
    {
        $this->configure(false);

        $this->assertFalse((new Sdk($this->config, $this->logger))->isConfigured());
    }

    public function testUnsupportedCurrencyIsNotConfigured(): void
    {
        $this->configure(true, 'EUR');

        $this->assertFalse((new Sdk($this->config, $this->logger))->isConfigured());
    }

    public function testMissingPublicKeyIsNotConfigured(): void
    {
        $this->configure(true, 'USD', '  ');

        $this->assertFalse((new Sdk($this->config, $this->logger))->isConfigured());
    }

    public function testConfigurationIsReadOnce(): void
    {
        $this->config->method('getConfigData')->willReturn('1');
        $this->config->method('getCurrency')->willReturn('USD');
        $this->config->expects($this->once())->method('getPublicApiKey')->willReturn('PUBLIC_KEY');
        $this->config->method('getScript')->willReturn(self::SCRIPT);
        $sdk = new Sdk($this->config, $this->logger);

        $sdk->isConfigured();
        $sdk->getLoaderConfig();
        $sdk->getLoaderConfig();
    }

    public function testFailuresAreNotConfiguredAndLoggedWithoutDetails(): void
    {
        $this->config->method('getConfigData')->willReturn('1');
        $this->config->method('getCurrency')->willReturn('USD');
        $this->config->method('getPublicApiKey')->willThrowException(new RuntimeException('store PUBLIC_KEY'));
        $this->logger->expects($this->once())->method('warning')->with(
            $this->anything(),
            ['exception' => RuntimeException::class]
        );
        $sdk = new Sdk($this->config, $this->logger);

        $this->assertFalse($sdk->isConfigured());
        $this->assertSame(
            ['public_api_key' => '', 'script' => '', 'locale' => '', 'country_code' => ''],
            $sdk->getLoaderConfig()
        );
    }

    public function testStoreFailuresAreNotConfigured(): void
    {
        $this->config->method('getConfigData')->willThrowException(new RuntimeException('no store'));
        $this->logger->expects($this->once())->method('warning');

        $this->assertFalse((new Sdk($this->config, $this->logger))->isConfigured());
    }
}
