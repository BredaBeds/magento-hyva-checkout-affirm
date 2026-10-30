<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Model\Attempt;

use LogicException;

/**
 * The attempt whose order is being placed in this request (a shared, in-memory service).
 *
 * Set by the confirmation pipeline around CartManagementInterface::placeOrder(); read by the submit guard,
 * which must let exactly this placement through (and only under the order number Affirm confirmed), and by the
 * Affirm client plugin, which records the authorization against it.
 */
class PlacementContext
{
    /**
     * @var string|null
     */
    private ?string $attemptId = null;

    /**
     * @var int|null
     */
    private ?int $quoteId = null;

    /**
     * @var string
     */
    private string $reservedIncrementId = '';

    /**
     * Mark the start of a placement; always pair with end() in a finally block.
     *
     * @param string $attemptId
     * @param int $quoteId
     * @param string $reservedIncrementId the order number the attempt was confirmed for ('' when unknown)
     * @return void
     * @throws LogicException when another placement is already running in this request
     */
    public function begin(
        #[\SensitiveParameter] string $attemptId,
        int $quoteId,
        string $reservedIncrementId = ''
    ): void {
        if ($this->attemptId !== null) {
            throw new LogicException('An Affirm order placement is already running in this request.');
        }
        $this->attemptId = $attemptId;
        $this->quoteId = $quoteId;
        $this->reservedIncrementId = $reservedIncrementId;
    }

    /**
     * Mark the end of the placement (idempotent).
     *
     * @return void
     */
    public function end(): void
    {
        $this->attemptId = null;
        $this->quoteId = null;
        $this->reservedIncrementId = '';
    }

    /**
     * The placement in progress, if any.
     *
     * @return array{attempt_id: string, quote_id: int, reserved_increment_id: string}|null
     */
    public function current(): ?array
    {
        if ($this->attemptId === null || $this->quoteId === null) {
            return null;
        }

        return [
            'attempt_id' => $this->attemptId,
            'quote_id' => $this->quoteId,
            'reserved_increment_id' => $this->reservedIncrementId,
        ];
    }
}
