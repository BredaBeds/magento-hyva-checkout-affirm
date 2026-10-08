<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Plugin\Framework\Session;

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Session\SessionStartChecker;

/**
 * Keeps the shopper's session cookie when Affirm returns with a cross-site POST.
 *
 * The browser does not send the SameSite=Lax session cookie with a cross-site POST, so starting a session would
 * create a new one and its Set-Cookie would replace the shopper's session (and cart). The confirm controller only
 * answers such a POST with a 303 to the GET confirm URL, which needs no session; the GET then carries the
 * shopper's cookies. Same approach as Magento_Paypal's TransparentSessionChecker.
 */
class SkipSessionOnConfirmPost
{
    private const CONFIRM_PATH_PATTERN = '#(?:^|/)hyva_affirm/checkout/confirm(?:/|$)#';

    /**
     * @param HttpRequest $request
     */
    public function __construct(
        private readonly HttpRequest $request
    ) {
    }

    /**
     * Refuse to start a session for a POST to the confirm controller.
     *
     * @param SessionStartChecker $subject
     * @param bool $result
     * @return bool
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterCheck(SessionStartChecker $subject, bool $result): bool
    {
        if (!$result || !$this->request->isPost()) {
            return $result;
        }

        return preg_match(self::CONFIRM_PATH_PATTERN, (string) $this->request->getPathInfo()) !== 1;
    }
}
