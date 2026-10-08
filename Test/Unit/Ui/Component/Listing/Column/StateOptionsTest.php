<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Test\Unit\Ui\Component\Listing\Column;

use Hyva\CheckoutAffirm\Model\Attempt\State;
use Hyva\CheckoutAffirm\Ui\Component\Listing\Column\StateOptions;
use PHPUnit\Framework\TestCase;

class StateOptionsTest extends TestCase
{
    public function testOffersExactlyTheStatesTheGridLists(): void
    {
        $options = (new StateOptions())->toOptionArray();
        $values = array_column($options, 'value');

        $expected = array_map(
            static fn (State $state): string => $state->value,
            [...State::unresolved(), ...State::open()]
        );
        sort($expected);
        $sorted = $values;
        sort($sorted);

        $this->assertSame($expected, $sorted);
        $this->assertSame('uncertain', $values[0], 'Attempts that need review come first.');
        foreach ($options as $option) {
            $this->assertNotSame('', $option['label']);
        }
    }
}
