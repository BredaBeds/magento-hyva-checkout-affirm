<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Observer;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Component\ComponentRegistrarInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Registers this module in app/etc/hyva-themes.json so Hyvä theme builds include its Tailwind sources.
 *
 * Hyvä's build joins each "src" to the Magento root, so the path must be relative to it.
 */
class HyvaConfigGenerateBefore implements ObserverInterface
{
    private const MODULE_NAME = 'Hyva_CheckoutAffirm';

    /**
     * @param ComponentRegistrarInterface $componentRegistrar
     * @param DirectoryList $directoryList
     */
    public function __construct(
        private readonly ComponentRegistrarInterface $componentRegistrar,
        private readonly DirectoryList $directoryList
    ) {
    }

    /**
     * Append {"src": "<module path relative to the Magento root>"} to the config's extensions.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $config = $observer->getData('config');
        $path = $this->componentRegistrar->getPath(ComponentRegistrar::MODULE, self::MODULE_NAME);
        if (!$config instanceof DataObject || $path === null || $path === '') {
            return;
        }

        $extensions = $config->hasData('extensions') ? $config->getData('extensions') : [];
        if (!is_array($extensions)) {
            $extensions = [];
        }
        $extensions[] = ['src' => $this->relativeToRoot($path)];
        $config->setData('extensions', $extensions);
    }

    /**
     * Path of $path relative to the Magento root, also when the module lives outside it.
     *
     * @param string $path
     * @return string
     */
    private function relativeToRoot(string $path): string
    {
        $path = rtrim($path, '/');
        $root = rtrim((string) $this->directoryList->getRoot(), '/');
        // Module paths are registered with symlinks resolved; the root may not be.
        $realRoot = realpath($root);

        foreach (array_unique([$root, $realRoot === false ? $root : $realRoot]) as $candidate) {
            if (str_starts_with($path . '/', $candidate . '/')) {
                return ltrim(substr($path, strlen($candidate)), '/');
            }
        }

        // Outside the root (e.g. a path repository elsewhere): walk up from the root.
        $notEmpty = static fn (string $part): bool => $part !== '';
        $from = array_values(array_filter(explode('/', $root), $notEmpty));
        $to = array_values(array_filter(explode('/', $path), $notEmpty));
        $common = 0;
        while (isset($from[$common], $to[$common]) && $from[$common] === $to[$common]) {
            $common++;
        }

        return str_repeat('../', count($from) - $common) . implode('/', array_slice($to, $common));
    }
}
