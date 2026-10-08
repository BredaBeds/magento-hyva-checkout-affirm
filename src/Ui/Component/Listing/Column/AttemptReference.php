<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Ui\Component\Listing\Column;

use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Shortened attempt id for the grid; the full id is printed by "bin/magento hyva:affirm:attempt:list".
 */
class AttemptReference extends Column
{
    private const VISIBLE_CHARACTERS = 12;

    /**
     * Add the shortened id to every row.
     *
     * @param array<string, mixed> $dataSource
     * @return array<string, mixed>
     */
    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items']) || !is_array($dataSource['data']['items'])) {
            return $dataSource;
        }
        $name = (string) $this->getData('name');
        foreach ($dataSource['data']['items'] as &$item) {
            $attemptId = is_array($item) && is_string($item['attempt_id'] ?? null) ? $item['attempt_id'] : '';
            $item[$name] = $attemptId === '' ? '' : substr($attemptId, 0, self::VISIBLE_CHARACTERS) . "\u{2026}";
        }
        unset($item);

        return $dataSource;
    }
}
