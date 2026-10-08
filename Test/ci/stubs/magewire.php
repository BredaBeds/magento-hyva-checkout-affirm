<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 *
 * Test double written from the public API for CI only; not the original code; MIT.
 *
 * Stands in for the Magewire component base class of magewirephp/magewire (public signature as of 1.13.3) so CI
 * runs without Hyvä packages. Only the members this module's code and unit tests use are declared, typed as in
 * the original: the component redeclares $listeners (untyped) and $uncallables (array), and PHP rejects a
 * redeclaration whose type differs from the parent's.
 */
declare(strict_types=1);

namespace Magewirephp\Magewire;

use Magento\Framework\View\Element\Block\ArgumentInterface;

abstract class Component implements ArgumentInterface
{
    protected $listeners = [];

    /**
     * Public methods the browser may not call.
     *
     * @var string[]
     */
    protected array $uncallables = [];
}
