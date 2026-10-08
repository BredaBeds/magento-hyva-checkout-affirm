<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Test\Unit\ViewModel;

use Astound\Affirm\Model\Config as AffirmConfig;
use Hyva\CheckoutAffirm\ViewModel\Method;
use Magento\Framework\Phrase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class MethodTest extends TestCase
{
    private const AFFIRM_ICON = 'https://cdn-assets.affirm.com/images/blue_logo-transparent_bg.png';
    private const EDU_DESC = 'You will be redirected to Affirm to securely complete your purchase.';

    /** @var list<string> */
    private array $warnings = [];

    /**
     * @param array<string, mixed> $settings payment/affirm_gateway/<field> values
     */
    private function method(array $settings, bool $edu = false, bool $broken = false): Method
    {
        $config = $this->createMock(AffirmConfig::class);
        if ($broken) {
            $config->method('getConfigData')->willThrowException(new RuntimeException('no store'));
            $config->method('getEdu')->willThrowException(new RuntimeException('no store'));
            $config->method('getDefaultEduDesc')->willThrowException(new RuntimeException('no store'));
        } else {
            $config->method('getConfigData')->willReturnCallback(
                static fn (string $field): mixed => $settings[$field] ?? null
            );
            $config->method('getEdu')->willReturn($edu);
            $config->method('getDefaultEduDesc')->willReturn(
                new Phrase('You will be redirected to Affirm to securely complete your purchase.')
            );
        }

        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function (string $message, array $context = []): void {
            $this->warnings[] = $message . ' ' . json_encode($context);
        });

        return new Method($config, $logger);
    }

    public function testIconAndInfoModeIsTheDefault(): void
    {
        $method = $this->method(['info' => ' Pay <b>over</b> time ', 'icon' => self::AFFIRM_ICON]);

        $this->assertFalse($method->isPlainText());
        $this->assertSame('Pay <b>over</b> time', $method->getInfo());
        $this->assertSame('', $method->getIconUrl(), "Affirm's default icon is not repeated in the body");
        $this->assertSame('Affirm', $method->getTitle());
    }

    public function testPlainTextModeShowsNeitherIconNorInfo(): void
    {
        $method = $this->method([
            'control' => '1',
            'title' => 'Monthly payments',
            'info' => 'Pay over time',
            'icon' => 'https://cdn.example.com/own.png',
        ]);

        $this->assertTrue($method->isPlainText());
        $this->assertSame('Monthly payments', $method->getTitle());
        $this->assertSame('', $method->getInfo());
        $this->assertSame('', $method->getIconUrl());
    }

    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function icons(): array
    {
        return [
            'own https icon' => ['https://cdn.example.com/own.png', 'https://cdn.example.com/own.png'],
            'own icon, upper-case scheme' => ['HTTPS://cdn.example.com/own.png', 'HTTPS://cdn.example.com/own.png'],
            "Affirm's default" => [self::AFFIRM_ICON, ''],
            'plain http' => ['http://cdn.example.com/own.png', ''],
            'script url' => ['javascript:alert(1)', ''],
            'relative path' => ['/media/own.png', ''],
            'empty' => ['', ''],
            'not set' => [null, ''],
        ];
    }

    #[DataProvider('icons')]
    public function testOnlyAMerchantsHttpsIconIsShown(mixed $icon, string $expected): void
    {
        $this->assertSame($expected, $this->method(['icon' => $icon])->getIconUrl());
    }

    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function flows(): array
    {
        return [
            "Affirm's default" => ['Modal', 'modal'],
            'modal' => ['modal', 'modal'],
            'redirect' => ['redirect', 'redirect'],
            'redirect, other case' => [' Redirect ', 'redirect'],
            'not set' => [null, 'modal'],
            'unknown' => ['popup', 'modal'],
        ];
    }

    #[DataProvider('flows')]
    public function testCheckoutFlowIsNormalised(mixed $value, string $mode): void
    {
        $method = $this->method(['checkout_flow_type' => $value]);

        $this->assertSame($mode, $method->getMode());
        $this->assertSame($mode === 'redirect', $method->isRedirect());
    }

    public function testRedirectWithoutTheWidgetShowsAffirmsDescription(): void
    {
        $method = $this->method(['checkout_flow_type' => 'redirect']);

        $this->assertFalse($method->isEduEnabled());
        $this->assertSame(self::EDU_DESC, $method->getDefaultEduDesc());
        $this->assertSame(self::EDU_DESC, $method->getCopy());
    }

    public function testRedirectWithTheWidgetShowsTheRedirectNotice(): void
    {
        $method = $this->method(['checkout_flow_type' => 'redirect'], true);

        $this->assertTrue($method->isEduEnabled());
        $this->assertSame('You will be redirected to Affirm to complete your purchase.', $method->getCopy());
    }

    public function testModalShowsTheModalNotice(): void
    {
        foreach ([false, true] as $edu) {
            $method = $this->method(['checkout_flow_type' => 'Modal'], $edu);

            $this->assertSame('An Affirm window will open to complete your purchase.', $method->getCopy());
            $this->assertSame($method->getModeNotice(), $method->getCopy());
        }
    }

    public function testUnreadableSettingsFallBackSafely(): void
    {
        $method = $this->method([], false, true);

        $this->assertSame('Affirm', $method->getTitle());
        $this->assertFalse($method->isPlainText());
        $this->assertSame('', $method->getInfo());
        $this->assertSame('', $method->getIconUrl());
        $this->assertSame('modal', $method->getMode());
        $this->assertFalse($method->isEduEnabled());
        $this->assertSame('', $method->getDefaultEduDesc());
        $this->assertSame('An Affirm window will open to complete your purchase.', $method->getCopy());
        $this->assertNotEmpty($this->warnings);
        $this->assertStringNotContainsString('no store', implode("\n", $this->warnings));
    }

    public function testContractConstants(): void
    {
        $this->assertSame('hyva-affirm-inline-checkout', Method::INLINE_CONTAINER_ID);
        $this->assertNotContains('script', Method::INFO_ALLOWED_TAGS);
        $this->assertNotContains('img', Method::INFO_ALLOWED_TAGS);
    }
}
