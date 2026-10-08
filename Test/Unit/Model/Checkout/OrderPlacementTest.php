<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Test\Unit\Model\Checkout;

use Hyva\CheckoutAffirm\Model\Attempt\PlacementContext;
use Hyva\CheckoutAffirm\Model\Checkout\OrderPlacement;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Payment;
use Magento\Quote\Model\ResourceModel\Quote as QuoteResource;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class OrderPlacementTest extends TestCase
{
    private const ATTEMPT_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /**
     * @var CartManagementInterface&MockObject
     */
    private CartManagementInterface $cartManagement;

    /**
     * @var PlacementContext
     */
    private PlacementContext $context;

    /**
     * @var OrderRepositoryInterface&MockObject
     */
    private OrderRepositoryInterface $orderRepository;

    /**
     * @var SearchCriteriaBuilder&MockObject
     */
    private SearchCriteriaBuilder $criteriaBuilder;

    /**
     * @var QuoteResource&MockObject
     */
    private QuoteResource $quoteResource;

    /**
     * @var Quote&MockObject
     */
    private Quote $quote;

    /**
     * @var Payment&MockObject
     */
    private Payment $payment;

    /**
     * @var OrderPlacement
     */
    private OrderPlacement $placement;

    protected function setUp(): void
    {
        $this->cartManagement = $this->createMock(CartManagementInterface::class);
        $this->context = new PlacementContext();
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->criteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $this->quoteResource = $this->createMock(QuoteResource::class);
        $this->payment = $this->createMock(Payment::class);
        $this->quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getPayment'])
            ->getMock();
        $this->quote->method('getId')->willReturn('42');
        $this->quote->method('getPayment')->willReturn($this->payment);

        $this->placement = new OrderPlacement(
            $this->cartManagement,
            $this->context,
            $this->orderRepository,
            $this->criteriaBuilder,
            $this->quoteResource
        );
    }

    public function testPlacesThroughCartManagementWithThePlacementMarked(): void
    {
        $this->cartManagement->expects($this->once())
            ->method('placeOrder')
            ->with(42, $this->payment)
            ->willReturnCallback(function (): string {
                $this->assertSame(
                    ['attempt_id' => self::ATTEMPT_ID, 'quote_id' => 42, 'reserved_increment_id' => '000000123'],
                    $this->context->current()
                );

                return '555';
            });

        $this->assertSame(555, $this->placement->place($this->quote, self::ATTEMPT_ID, '000000123'));
        $this->assertNull($this->context->current());
    }

    public function testClearsThePlacementMarkWhenPlacementThrows(): void
    {
        $this->cartManagement->method('placeOrder')->willThrowException(new RuntimeException('failed'));

        try {
            $this->placement->place($this->quote, self::ATTEMPT_ID, '000000123');
            $this->fail('The exception must reach the caller.');
        } catch (RuntimeException $exception) {
            $this->assertSame('failed', $exception->getMessage());
        }
        $this->assertNull($this->context->current());
    }

    public function testGetOrder(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $this->orderRepository->method('get')->willReturnCallback(
            fn (int $id) => $id === 555 ? $order : throw new NoSuchEntityException()
        );

        $this->assertSame($order, $this->placement->getOrder(555));
        $this->assertNull($this->placement->getOrder(556));
        $this->assertNull($this->placement->getOrder(0));
    }

    public function testFindsTheOrderByIncrementIdAndStore(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $criteria = $this->createMock(SearchCriteria::class);
        $filters = [];
        $this->criteriaBuilder->method('addFilter')->willReturnCallback(
            function (string $field, $value) use (&$filters) {
                $filters[$field] = $value;

                return $this->criteriaBuilder;
            }
        );
        $this->criteriaBuilder->expects($this->once())->method('setPageSize')->with(1)->willReturnSelf();
        $this->criteriaBuilder->method('create')->willReturn($criteria);
        $result = $this->createMock(OrderSearchResultInterface::class);
        $result->method('getItems')->willReturn([7 => $order]);
        $this->orderRepository->expects($this->once())->method('getList')->with($criteria)->willReturn($result);

        $this->assertSame($order, $this->placement->findOrder('000000123', 3));
        $this->assertSame(['increment_id' => '000000123', 'store_id' => 3], $filters);
    }

    public function testFindsNothing(): void
    {
        $this->criteriaBuilder->method('addFilter')->willReturnSelf();
        $this->criteriaBuilder->method('setPageSize')->willReturnSelf();
        $this->criteriaBuilder->method('create')->willReturn($this->createMock(SearchCriteria::class));
        $result = $this->createMock(OrderSearchResultInterface::class);
        $result->method('getItems')->willReturn([]);
        $this->orderRepository->method('getList')->willReturn($result);

        $this->assertNull($this->placement->findOrder('000000123', 3));
        $this->assertNull($this->placement->findOrder('', 3));
    }

    public function testDeactivatesOnlyTheActiveFlag(): void
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId'])
            ->getMock();
        $quote->method('getId')->willReturn(42);
        $quote->setData('is_active', 1);
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->once())
            ->method('update')
            ->with('prefix_quote', ['is_active' => 0], ['entity_id = ?' => 42, 'is_active = ?' => 1])
            ->willReturn(1);
        $this->quoteResource->method('getConnection')->willReturn($connection);
        $this->quoteResource->method('getMainTable')->willReturn('prefix_quote');

        $this->assertTrue($this->placement->deactivateQuote($quote));
        $this->assertFalse((bool) $quote->getIsActive());
    }

    public function testDeactivatesAStoredQuoteById(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->exactly(2))
            ->method('update')
            ->with('prefix_quote', ['is_active' => 0], ['entity_id = ?' => 42, 'is_active = ?' => 1])
            ->willReturnOnConsecutiveCalls(1, 0);
        $this->quoteResource->method('getConnection')->willReturn($connection);
        $this->quoteResource->method('getMainTable')->willReturn('prefix_quote');

        $this->assertTrue($this->placement->deactivateQuoteById(42));
        $this->assertFalse($this->placement->deactivateQuoteById(42), 'Already inactive.');
        $this->assertFalse($this->placement->deactivateQuoteById(0));
    }
}
