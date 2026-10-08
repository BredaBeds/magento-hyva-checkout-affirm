<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Model\Checkout;

use Hyva\CheckoutAffirm\Model\Attempt\PlacementContext;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\ResourceModel\Quote as QuoteResource;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * Magento's standard order placement for a claimed attempt, plus the order lookups and the cart deactivation used
 * when a placement ends in an exception or its process died.
 */
class OrderPlacement
{
    /**
     * @param CartManagementInterface $cartManagement
     * @param PlacementContext $placementContext
     * @param OrderRepositoryInterface $orderRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param QuoteResource $quoteResource
     */
    public function __construct(
        private readonly CartManagementInterface $cartManagement,
        private readonly PlacementContext $placementContext,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly QuoteResource $quoteResource
    ) {
    }

    /**
     * Place the order exactly like Hyvä Checkout's default service does.
     *
     * The call runs inside Magento's cart mutex and re-validates the payment method (payment_method_is_active
     * observers included). The attempt is registered as the placement in progress for the duration of the call,
     * so the submit guard lets exactly this placement through, and only under the order number Affirm confirmed,
     * and the Affirm client plugin can record the authorization against it.
     *
     * @param Quote $quote
     * @param string $attemptId
     * @param string $reservedIncrementId the attempt's reserved order number
     * @return int order id
     */
    public function place(
        Quote $quote,
        #[\SensitiveParameter] string $attemptId,
        string $reservedIncrementId
    ): int {
        $quoteId = (int) $quote->getId();
        $this->placementContext->begin($attemptId, $quoteId, $reservedIncrementId);
        try {
            return (int) $this->cartManagement->placeOrder($quoteId, $quote->getPayment());
        } finally {
            $this->placementContext->end();
        }
    }

    /**
     * Load an order by id; null when it does not exist.
     *
     * @param int $orderId
     * @return OrderInterface|null
     */
    public function getOrder(int $orderId): ?OrderInterface
    {
        if ($orderId <= 0) {
            return null;
        }
        try {
            return $this->orderRepository->get($orderId);
        } catch (NoSuchEntityException) {
            return null;
        }
    }

    /**
     * Find the order created from a reserved increment id in a store (unique in Magento).
     *
     * @param string $incrementId
     * @param int $storeId
     * @return OrderInterface|null
     */
    public function findOrder(string $incrementId, int $storeId): ?OrderInterface
    {
        if ($incrementId === '') {
            return null;
        }
        $criteria = $this->searchCriteriaBuilder
            ->addFilter(OrderInterface::INCREMENT_ID, $incrementId)
            ->addFilter(OrderInterface::STORE_ID, $storeId)
            ->setPageSize(1)
            ->create();
        foreach ($this->orderRepository->getList($criteria)->getItems() as $order) {
            return $order;
        }

        return null;
    }

    /**
     * Deactivate the quote of an order whose placement was interrupted after the order was saved.
     *
     * Only the is_active column is written, directly: saving the in-memory quote (or a repository copy) could
     * write back stale data. The instance in memory is updated too, so a later save cannot re-activate the cart.
     *
     * @param Quote $quote
     * @return bool whether the stored quote was still active
     */
    public function deactivateQuote(Quote $quote): bool
    {
        $quote->setIsActive(false);

        return $this->deactivateQuoteById((int) $quote->getId());
    }

    /**
     * Deactivate a stored quote by id (no instance in memory, for example in a cron job); only is_active is written.
     *
     * @param int $quoteId
     * @return bool whether the stored quote was still active
     */
    public function deactivateQuoteById(int $quoteId): bool
    {
        $connection = $this->quoteResource->getConnection();
        if ($quoteId <= 0 || $connection === false) {
            return false;
        }

        return $connection->update(
            $this->quoteResource->getMainTable(),
            ['is_active' => 0],
            ['entity_id = ?' => $quoteId, 'is_active = ?' => 1]
        ) > 0;
    }
}
