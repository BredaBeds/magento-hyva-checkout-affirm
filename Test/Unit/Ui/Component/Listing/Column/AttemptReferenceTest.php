<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Test\Unit\Ui\Component\Listing\Column;

use Hyva\CheckoutAffirm\Ui\Component\Listing\Column\AttemptReference;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use PHPUnit\Framework\TestCase;

class AttemptReferenceTest extends TestCase
{
    public function testShowsTheBeginningOfTheAttemptId(): void
    {
        $column = new AttemptReference(
            $this->createMock(ContextInterface::class),
            $this->createMock(UiComponentFactory::class),
            [],
            ['name' => 'attempt_ref']
        );
        $id = '0123456789ab' . str_repeat('f', 52);

        $result = $column->prepareDataSource(['data' => ['items' => [
            ['attempt_id' => $id, 'state' => 'uncertain'],
            ['attempt_id' => null],
            [],
        ]]]);

        $this->assertSame([
            ['attempt_id' => $id, 'state' => 'uncertain', 'attempt_ref' => "0123456789ab\u{2026}"],
            ['attempt_id' => null, 'attempt_ref' => ''],
            ['attempt_ref' => ''],
        ], $result['data']['items']);
    }

    public function testLeavesADataSourceWithoutItemsAlone(): void
    {
        $column = new AttemptReference(
            $this->createMock(ContextInterface::class),
            $this->createMock(UiComponentFactory::class),
            [],
            ['name' => 'attempt_ref']
        );

        $dataSource = ['data' => ['totalRecords' => 0]];

        $this->assertSame($dataSource, $column->prepareDataSource($dataSource));
    }
}
