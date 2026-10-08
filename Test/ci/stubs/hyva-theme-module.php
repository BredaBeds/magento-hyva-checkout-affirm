<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 *
 * Test double written from the public API for CI only; not the original code; MIT.
 *
 * Stands in for the classes of hyva-themes/magento2-theme-module (public signatures as of 1.5.2) that this
 * module's templates and unit tests use, so CI runs without Hyvä packages. Only the members used here are
 * declared, with the same names, parameter types and return types. Both methods need a Magento runtime and
 * throw: unit tests mock them.
 */
declare(strict_types=1);

namespace Hyva\Theme\Model {
    use Magento\Framework\View\Element\AbstractBlock;
    use Magento\Framework\View\Element\Block\ArgumentInterface;

    class ViewModelRegistry
    {
        /**
         * @template T of ArgumentInterface
         * @param class-string<T> $viewModelClass
         * @param AbstractBlock|null $block
         * @return T
         */
        public function require(string $viewModelClass, ?AbstractBlock $block = null): ArgumentInterface
        {
            throw new \LogicException('Test double: mock ' . __METHOD__ . '().');
        }
    }
}

namespace Hyva\Theme\ViewModel {
    use Magento\Framework\View\Element\Block\ArgumentInterface;

    class HyvaCsp implements ArgumentInterface
    {
        public function registerInlineScript(): void
        {
            throw new \LogicException('Test double: mock ' . __METHOD__ . '().');
        }
    }
}
