<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Ui\Component\Listing\Column;

use Hyva\CheckoutAffirm\Model\Attempt\State;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * The states the attempts grid lists (unresolved and open), those that may hold an Affirm authorization first.
 */
class StateOptions implements OptionSourceInterface
{
    /**
     * Options for the state column and its filter.
     *
     * @return list<array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => State::UNCERTAIN->value, 'label' => (string) __('Uncertain (needs review)')],
            ['value' => State::AUTHORIZING->value, 'label' => (string) __('Authorizing')],
            ['value' => State::PLACING->value, 'label' => (string) __('Placing order')],
            ['value' => State::CONFIRMED->value, 'label' => (string) __('Confirmed by Affirm')],
            ['value' => State::INITIATED->value, 'label' => (string) __('Started')],
        ];
    }
}
