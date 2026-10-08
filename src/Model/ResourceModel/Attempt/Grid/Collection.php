<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Model\ResourceModel\Attempt\Grid;

use Hyva\CheckoutAffirm\Model\Attempt\Attempt;
use Hyva\CheckoutAffirm\Model\Attempt\State;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;

/**
 * Data source of the admin attempts grid (main table and identifier come from etc/di.xml).
 *
 * The grid is the reconciliation view: attempts that block their cart (placing, authorizing, uncertain) and attempts
 * a shopper may still complete (initiated or confirmed, not yet expired). Finished attempts are listed by
 * "bin/magento hyva:affirm:attempt:list --all"; abandoned ones are expired by the daily cleanup.
 */
class Collection extends SearchResult
{
    /**
     * Grid column showing the shortened attempt id; filtering on it searches the full id.
     */
    public const REFERENCE_FIELD = 'attempt_ref';

    /**
     * Limit the grid to the reconciliation view and map the reference column to the full id.
     *
     * @return $this
     */
    protected function _initSelect()
    {
        parent::_initSelect();

        $connection = $this->getConnection();
        $this->getSelect()->where(sprintf(
            '%s OR (%s AND %s)',
            $connection->quoteInto('main_table.state IN (?)', $this->values(State::unresolved())),
            $connection->quoteInto('main_table.state IN (?)', $this->values(State::open())),
            // Timestamps are written in UTC by the repository; Magento's connection runs in UTC.
            $connection->quoteInto('main_table.expires_at > ?', gmdate(Attempt::DATE_FORMAT, $this->currentTime()))
        ));
        $this->addFilterToMap(self::REFERENCE_FIELD, 'main_table.attempt_id');

        return $this;
    }

    /**
     * Current Unix time.
     *
     * @return int
     */
    protected function currentTime(): int
    {
        return time();
    }

    /**
     * Enum values for an IN (...) clause.
     *
     * @param list<State> $states
     * @return list<string>
     */
    private function values(array $states): array
    {
        return array_map(static fn (State $state): string => $state->value, $states);
    }
}
