<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Test\Unit\Model\Attempt;

use Hyva\CheckoutAffirm\Model\Attempt\AdminNotifier;
use Magento\Framework\Notification\NotifierInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class AdminNotifierTest extends TestCase
{
    public function testAddsAMajorInboxMessageNamingTheOrder(): void
    {
        $notifier = $this->createMock(NotifierInterface::class);
        $notifier->expects($this->once())
            ->method('addMajor')
            ->willReturnCallback(function ($title, $description, $url = '', $isInternal = true) use ($notifier) {
                $this->assertIsString($title);
                $this->assertIsString($description);
                $this->assertStringContainsString('000000123', $title);
                $this->assertStringContainsString('000000123', $description);
                $this->assertStringContainsString('hyva:affirm:attempt:resolve', $description);
                $this->assertSame('', $url);
                $this->assertTrue($isInternal);

                return $notifier;
            });

        (new AdminNotifier($notifier, $this->createMock(LoggerInterface::class)))->notifyUncertain('000000123');
    }

    public function testNeverThrows(): void
    {
        $notifier = $this->createMock(NotifierInterface::class);
        $notifier->method('addMajor')->willThrowException(new RuntimeException('inbox table missing'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        (new AdminNotifier($notifier, $logger))->notifyUncertain('000000123');
    }
}
