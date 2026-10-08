<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Model\Attempt;

/**
 * Binds attempts to the checkout session that started them. The raw key never leaves the session;
 * attempts store only its SHA-256 hash.
 */
interface OwnerKeyInterface
{
    public const SESSION_KEY = 'hyva_affirm_owner_key';

    /**
     * Hash of this checkout session's owner key; creates the key on first use.
     *
     * @return string 64 lowercase hex characters
     */
    public function getHash(): string;

    /**
     * Whether a stored owner_key_hash belongs to this checkout session. Never creates a key.
     *
     * @param string $ownerKeyHash
     * @return bool
     */
    public function matches(string $ownerKeyHash): bool;
}
