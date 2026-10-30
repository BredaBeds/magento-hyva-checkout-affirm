<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Plugin\Affirm;

use Astound\Affirm\Gateway\Http\Client\ClientService;
use Hyva\CheckoutAffirm\Model\Attempt\AttemptRepositoryInterface;
use Hyva\CheckoutAffirm\Model\Attempt\PlacementContext;
use Hyva\CheckoutAffirm\Model\Attempt\State;
use Magento\Framework\App\ResourceConnection;
use Magento\Payment\Gateway\Http\ClientException;
use Magento\Payment\Gateway\Http\TransferInterface;
use Psr\Log\LoggerInterface;

/**
 * Records when Affirm's authorization request is sent for the attempt being placed.
 *
 * Before the authorize POST leaves, the attempt moves placing -> authorizing, so an exception after that point
 * (lost response, failed capture under authorize_capture, failed order save) is treated as "Affirm may hold an
 * authorization" and never as a clean failure. Capture, void and refund (POST .../transactions/{id}/...) and the
 * pre-authorization read (GET /api/v2/checkout/{token}) pass through untouched.
 */
class ClientServiceAttemptTracker
{
    private const AUTHORIZE_PATH = '/api/v1/transactions';

    /**
     * @param PlacementContext $placementContext
     * @param AttemptRepositoryInterface $attempts
     * @param ResourceConnection $resource
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly PlacementContext $placementContext,
        private readonly AttemptRepositoryInterface $attempts,
        private readonly ResourceConnection $resource,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Mark the attempt authorizing before the authorize request and store Affirm's transaction id after it.
     *
     * @param ClientService $subject
     * @param callable $proceed
     * @param TransferInterface $transferObject
     * @return mixed
     * @throws ClientException when the attempt is no longer being placed (the request is not sent)
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundPlaceRequest(
        ClientService $subject,
        callable $proceed,
        TransferInterface $transferObject
    ): mixed {
        $placement = $this->placementContext->current();
        if ($placement === null || !$this->isAuthorization($transferObject)) {
            return $proceed($transferObject);
        }
        $attemptId = $placement['attempt_id'];

        if ($this->resource->getConnection()->getTransactionLevel() > 0) {
            // The state change below would be rolled back with the surrounding transaction if placement fails.
            $this->logger->critical(
                'Affirm checkout: the authorization is sent inside an open database transaction; '
                . 'a failed placement may not be recorded for review.'
            );
        }
        if (!$this->attempts->transition($attemptId, [State::PLACING], State::AUTHORIZING)) {
            $this->logger->error(
                'Affirm checkout: authorization not sent because the checkout attempt is no longer being placed.'
            );
            throw new ClientException(__('The Affirm payment could not be authorized. Please try again.'));
        }

        $result = $proceed($transferObject);

        if (is_array($result) && ($result['status'] ?? null) === 'authorized') {
            $this->recordTransactionId($attemptId, $result['id'] ?? null);
        } elseif ($this->isRefusal($result)) {
            // Affirm refused the request and created no transaction: the placement fails like one that never
            // reached Affirm, instead of leaving the cart blocked for review.
            $this->attempts->transition($attemptId, [State::AUTHORIZING], State::PLACING);
        }

        return $result;
    }

    /**
     * Whether Affirm answered the authorize request with a definite refusal (an error body, no transaction).
     *
     * Affirm's client returns 4xx error bodies ({status_code, type, code, message}) instead of throwing. 408
     * (timeout) and 409 (conflict, for example an already authorized checkout) do not prove that nothing was
     * authorized, so they keep the attempt for review, as do 5xx answers and transport errors.
     *
     * @param mixed $result
     * @return bool
     */
    private function isRefusal(mixed $result): bool
    {
        if (!is_array($result) || isset($result['id']) || !is_numeric($result['status_code'] ?? null)) {
            return false;
        }
        $status = (int) $result['status_code'];

        return $status >= 400 && $status < 500 && $status !== 408 && $status !== 409;
    }

    /**
     * Whether the transfer is Affirm's authorize call (POST <api>/api/v1/transactions, without an id segment).
     *
     * @param TransferInterface $transferObject
     * @return bool
     */
    private function isAuthorization(TransferInterface $transferObject): bool
    {
        if (strtoupper((string) $transferObject->getMethod()) !== 'POST') {
            return false;
        }
        $path = parse_url((string) $transferObject->getUri(), PHP_URL_PATH);

        return is_string($path) && str_ends_with(rtrim($path, '/'), self::AUTHORIZE_PATH);
    }

    /**
     * Store the transaction id for reconciliation; a failure here must not fail an authorized placement.
     *
     * @param string $attemptId
     * @param mixed $transactionId
     * @return void
     */
    private function recordTransactionId(#[\SensitiveParameter] string $attemptId, mixed $transactionId): void
    {
        if (!is_string($transactionId) && !is_int($transactionId)) {
            return;
        }
        try {
            $this->attempts->recordTransactionId($attemptId, (string) $transactionId);
        } catch (\Throwable $exception) {
            $this->logger->warning(
                'Affirm checkout: the authorization transaction id could not be stored on the checkout attempt.',
                ['exception_class' => get_class($exception)]
            );
        }
    }
}
