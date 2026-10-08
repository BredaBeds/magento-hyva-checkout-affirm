<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Test\Unit\Model\Checkout;

use Hyva\CheckoutAffirm\Model\Checkout\PrivateContentInvalidator;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\Cookie\PublicCookieMetadata;
use Magento\Framework\Stdlib\CookieManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PrivateContentInvalidatorTest extends TestCase
{
    /**
     * Same metadata as Magento\Framework\App\PageCache\Version::process().
     */
    public function testSetsAFreshVersionWithMagentosCookieMetadata(): void
    {
        foreach ([true, false] as $secure) {
            $metadata = new PublicCookieMetadata();
            $factory = $this->createMock(CookieMetadataFactory::class);
            $factory->method('createPublicCookieMetadata')->willReturn($metadata);
            $request = $this->createMock(Http::class);
            $request->method('isSecure')->willReturn($secure);
            $values = [];
            $cookieManager = $this->createMock(CookieManagerInterface::class);
            $cookieManager->expects($this->exactly(2))
                ->method('setPublicCookie')
                ->willReturnCallback(function (string $name, string $value, $passed) use ($metadata, &$values) {
                    $this->assertSame('private_content_version', $name);
                    $this->assertSame($metadata, $passed);
                    $values[] = $value;
                });

            $invalidator = new PrivateContentInvalidator(
                $cookieManager,
                $factory,
                $request,
                $this->createMock(LoggerInterface::class)
            );
            $invalidator->invalidate();
            $invalidator->invalidate();

            $this->assertSame(315360000, $metadata->getDuration());
            $this->assertSame('/', $metadata->getPath());
            $this->assertSame($secure, $metadata->getSecure());
            $this->assertFalse($metadata->getHttpOnly());
            $this->assertSame('Lax', $metadata->getSameSite());
            $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $values[0]);
            $this->assertNotSame($values[0], $values[1]);
        }
    }

    public function testNeverThrows(): void
    {
        $factory = $this->createMock(CookieMetadataFactory::class);
        $factory->method('createPublicCookieMetadata')->willReturn(new PublicCookieMetadata());
        $cookieManager = $this->createMock(CookieManagerInterface::class);
        $cookieManager->method('setPublicCookie')->willThrowException(new InputException(__('headers sent')));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        (new PrivateContentInvalidator($cookieManager, $factory, $this->createMock(Http::class), $logger))
            ->invalidate();
    }
}
