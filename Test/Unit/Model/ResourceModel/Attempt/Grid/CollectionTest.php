<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Test\Unit\Model\ResourceModel\Attempt\Grid;

use Hyva\CheckoutAffirm\Model\ResourceModel\Attempt\Grid\Collection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class CollectionTest extends TestCase
{
    public function testExtendsTheUiSearchResult(): void
    {
        $this->assertTrue(is_subclass_of(Collection::class, SearchResult::class));
        // etc/di.xml injects mainTable and identifierName into SearchResult's constructor.
        $this->assertSame(
            SearchResult::class,
            (new ReflectionMethod(Collection::class, '__construct'))->getDeclaringClass()->getName()
        );
    }

    public function testListsUnresolvedAndUnexpiredOpenAttempts(): void
    {
        $from = [];
        $where = [];
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnCallback(function ($name) use (&$from, $select) {
            $from[] = $name;

            return $select;
        });
        $select->method('where')->willReturnCallback(function ($condition) use (&$where, $select) {
            $where[] = $condition;

            return $select;
        });
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('quoteInto')->willReturnCallback(
            static fn (string $text, mixed $value): string => str_replace(
                '?',
                is_array($value) ? "'" . implode("', '", $value) . "'" : "'" . $value . "'",
                $text
            )
        );

        /** @var Collection&MockObject $collection */
        $collection = $this->getMockBuilder(Collection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getSelect', 'getConnection', 'getMainTable', 'currentTime'])
            ->getMock();
        $collection->method('getSelect')->willReturn($select);
        $collection->method('getConnection')->willReturn($connection);
        $collection->method('getMainTable')->willReturn('prefix_hyva_affirm_checkout_attempt');
        $collection->method('currentTime')->willReturn(1791367200);

        $initSelect = new ReflectionMethod(Collection::class, '_initSelect');
        $this->assertSame($collection, $initSelect->invoke($collection));

        $this->assertSame([['main_table' => 'prefix_hyva_affirm_checkout_attempt']], $from);
        $this->assertSame([
            "main_table.state IN ('placing', 'authorizing', 'uncertain')"
            . " OR (main_table.state IN ('initiated', 'confirmed')"
            . " AND main_table.expires_at > '2026-10-07 10:00:00')",
        ], $where);

        $mapped = new ReflectionMethod(Collection::class, '_getMappedField');
        $this->assertSame('main_table.attempt_id', $mapped->invoke($collection, Collection::REFERENCE_FIELD));
        $this->assertSame('state', $mapped->invoke($collection, 'state'));
    }
}
