<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Test\Unit\Observer;

use Hyva\CheckoutAffirm\Observer\HyvaConfigGenerateBefore;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Component\ComponentRegistrarInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\TestCase;

class HyvaConfigGenerateBeforeTest extends TestCase
{
    private const ROOT = '/nonexistent-hyva-affirm-test/magento';

    public function testAppendsPathRelativeToRoot(): void
    {
        $config = new DataObject(['extensions' => [['src' => 'vendor/hyva-themes/magento2-theme-module/src']]]);

        $this->observer(self::ROOT . '/vendor/vendor/module/src')->execute(new Observer(['config' => $config]));

        $this->assertSame(
            [['src' => 'vendor/hyva-themes/magento2-theme-module/src'], ['src' => 'vendor/vendor/module/src']],
            $config->getData('extensions')
        );
    }

    public function testStartsListWhenEmpty(): void
    {
        $config = new DataObject();

        $this->observer(self::ROOT . '/packages/module/src/')->execute(new Observer(['config' => $config]));

        $this->assertSame([['src' => 'packages/module/src']], $config->getData('extensions'));
    }

    public function testPathOutsideRootWalksUp(): void
    {
        $config = new DataObject();

        $this->observer('/nonexistent-hyva-affirm-test/shared/module/src')
            ->execute(new Observer(['config' => $config]));

        $this->assertSame([['src' => '../shared/module/src']], $config->getData('extensions'));
    }

    public function testSiblingWithCommonPrefixIsNotTreatedAsInside(): void
    {
        $config = new DataObject();

        $this->observer(self::ROOT . '-other/module/src')->execute(new Observer(['config' => $config]));

        $this->assertSame([['src' => '../magento-other/module/src']], $config->getData('extensions'));
    }

    public function testUnregisteredModuleOrMissingConfigIsIgnored(): void
    {
        $config = new DataObject(['extensions' => []]);
        $this->observer(null)->execute(new Observer(['config' => $config]));
        $this->assertSame([], $config->getData('extensions'));

        $this->observer(self::ROOT . '/module')->execute(new Observer());
        $this->addToAssertionCount(1);
    }

    private function observer(?string $modulePath): HyvaConfigGenerateBefore
    {
        $registrar = $this->createMock(ComponentRegistrarInterface::class);
        $registrar->method('getPath')
            ->with(ComponentRegistrar::MODULE, 'Hyva_CheckoutAffirm')
            ->willReturn($modulePath);
        $directoryList = $this->createMock(DirectoryList::class);
        $directoryList->method('getRoot')->willReturn(self::ROOT);

        return new HyvaConfigGenerateBefore($registrar, $directoryList);
    }
}
