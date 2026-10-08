<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Test\Unit\Controller\Adminhtml\Attempt;

use Hyva\CheckoutAffirm\Controller\Adminhtml\Attempt\Index;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\View\Page\Config;
use Magento\Framework\View\Page\Title;
use PHPUnit\Framework\TestCase;

class IndexTest extends TestCase
{
    public function testIsAGetOnlyPageBehindTheAttemptsResource(): void
    {
        $this->assertSame('Hyva_CheckoutAffirm::attempts', Index::ADMIN_RESOURCE);
        $this->assertContains(HttpGetActionInterface::class, class_implements(Index::class) ?: []);
        $this->assertNotContains(HttpPostActionInterface::class, class_implements(Index::class) ?: []);
    }

    public function testRendersTheGridPage(): void
    {
        $title = $this->createMock(Title::class);
        $title->expects($this->once())->method('prepend')->with('Affirm Checkout Attempts');
        $config = $this->createMock(Config::class);
        $config->method('getTitle')->willReturn($title);
        $page = $this->createMock(Page::class);
        $page->expects($this->once())->method('setActiveMenu')->with('Hyva_CheckoutAffirm::attempts')->willReturnSelf();
        $page->method('getConfig')->willReturn($config);
        $resultFactory = $this->createMock(ResultFactory::class);
        $resultFactory->expects($this->once())->method('create')->with(ResultFactory::TYPE_PAGE)->willReturn($page);
        $context = $this->createMock(Context::class);
        $context->method('getResultFactory')->willReturn($resultFactory);

        $this->assertSame($page, (new Index($context))->execute());
    }
}
