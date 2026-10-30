<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Test\Unit\Model\Attempt;

use Hyva\CheckoutAffirm\Model\Attempt\PlacementContext;
use LogicException;
use PHPUnit\Framework\TestCase;

class PlacementContextTest extends TestCase
{
    public function testLifecycle(): void
    {
        $context = new PlacementContext();
        $this->assertNull($context->current());

        $context->begin('attempt', 42, '000000123');
        $this->assertSame(
            ['attempt_id' => 'attempt', 'quote_id' => 42, 'reserved_increment_id' => '000000123'],
            $context->current()
        );

        $context->end();
        $this->assertNull($context->current());
        $context->end();

        // The reserved number is optional and does not survive into the next placement.
        $context->begin('next', 43);
        $this->assertSame(
            ['attempt_id' => 'next', 'quote_id' => 43, 'reserved_increment_id' => ''],
            $context->current()
        );
    }

    public function testRejectsNestedPlacement(): void
    {
        $context = new PlacementContext();
        $context->begin('attempt', 42);

        $this->expectException(LogicException::class);
        $context->begin('other', 43);
    }
}
