<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Controller\Checkout;

use Hyva\CheckoutAffirm\Model\Attempt\Attempt;
use Hyva\CheckoutAffirm\Model\Checkout\ConfirmationOutcome;
use Hyva\CheckoutAffirm\Model\Checkout\ConfirmationPipelineInterface;
use Hyva\CheckoutAffirm\Model\Checkout\PaymentStep;
use Hyva\CheckoutAffirm\Model\Checkout\PrivateContentInvalidator;
use Hyva\CheckoutAffirm\Model\Checkout\SuccessSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\AbstractResult;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Message\ManagerInterface as MessageManagerInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\AbstractBlock;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use Psr\Log\LoggerInterface;

/**
 * Return URL of the redirect flow: hyva_affirm/checkout/confirm/attempt/<id>?checkout_token=<token>.
 *
 * GET runs the confirmation pipeline and answers with the success page, the in-progress page (hyva_affirm_
 * checkout_confirm handle) or the payment step with a message. A POST (Affirm's default action) changes nothing:
 * the browser does not send the SameSite=Lax session cookie with a cross-site POST, so it is answered with a 303
 * to the same URL as a GET, which carries the shopper's cookies.
 *
 * Responses are never cached and redirects away from this URL never carry the token.
 */
class Confirm implements HttpGetActionInterface, HttpPostActionInterface, CsrfAwareActionInterface
{
    public const ROUTE = 'hyva_affirm/checkout/confirm';
    public const STATUS_BLOCK = 'hyva.affirm.checkout.attempt.status';
    public const REFRESH_BLOCK = 'hyva.affirm.checkout.attempt.refresh';

    private const SUCCESS_ROUTE = 'checkout/onepage/success';
    private const TOKEN_PARAMS = ['checkout_token', 'checkout_id'];
    private const MAX_TOKEN_LENGTH = 512;

    /**
     * @param HttpRequest $request
     * @param RedirectFactory $redirectFactory
     * @param PageFactory $pageFactory
     * @param UrlInterface $url
     * @param MessageManagerInterface $messageManager
     * @param ConfirmationPipelineInterface $pipeline
     * @param SuccessSession $successSession
     * @param PrivateContentInvalidator $privateContentInvalidator
     * @param PaymentStep $paymentStep
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly HttpRequest $request,
        private readonly RedirectFactory $redirectFactory,
        private readonly PageFactory $pageFactory,
        private readonly UrlInterface $url,
        private readonly MessageManagerInterface $messageManager,
        private readonly ConfirmationPipelineInterface $pipeline,
        private readonly SuccessSession $successSession,
        private readonly PrivateContentInvalidator $privateContentInvalidator,
        private readonly PaymentStep $paymentStep,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Confirm the attempt (GET) or bounce a POST to the GET URL.
     *
     * @return ResultInterface a redirect, or the in-progress page
     */
    public function execute(): ResultInterface
    {
        $attemptId = $this->attemptId();
        $checkoutToken = $this->checkoutToken();

        if ($this->request->isPost()) {
            // No session is available here (see Plugin\Framework\Session\SkipSessionOnConfirmPost): change nothing.
            return $this->redirect($this->confirmUrl($attemptId, $checkoutToken), 303);
        }
        if (!Attempt::isValidId($attemptId)) {
            return $this->toPaymentStep(
                (string) __('We could not find this Affirm payment. Please start the payment again.')
            );
        }

        try {
            $outcome = $this->pipeline->confirm($attemptId, $checkoutToken);
        } catch (\Throwable $exception) {
            $this->logger->error(
                'Affirm checkout: the return from Affirm could not be processed.',
                ['exception_class' => get_class($exception)]
            );

            return $this->toPaymentStep((string) __('We could not confirm your Affirm payment. Please try again.'));
        }

        if ($outcome->isPlaced()) {
            try {
                $this->successSession->armForOutcome($outcome);
            } catch (\Throwable $exception) {
                // The order exists: the shopper still goes to the receipt; Magento's success page decides what it
                // can show (reloading the return URL arms it again through the replay).
                $this->logger->error(
                    'Affirm checkout: the success page could not be prepared for a placed order.',
                    ['exception_class' => get_class($exception)]
                );
            }
            $this->privateContentInvalidator->invalidate();

            return $this->redirect($this->url->getUrl(self::SUCCESS_ROUTE, ['_secure' => true]));
        }
        if ($outcome->isFailed()) {
            return $this->toPaymentStep($outcome->message);
        }
        if ($checkoutToken !== '' && !$outcome->isInProgress()) {
            // Following this attempt no longer needs the token: keep it out of the address bar and history. While
            // the attempt is in progress the page refreshes with the token, because a placement that hands the
            // attempt back (cart locked, Affirm unreachable) needs it again.
            return $this->redirect($this->confirmUrl($attemptId, ''));
        }

        return $this->statusPage($outcome);
    }

    /**
     * @inheritdoc
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * The POST from affirm.com carries no form key; it only leads to a GET of this URL.
     *
     * @param RequestInterface $request
     * @return bool|null
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    /**
     * In-progress page (refreshes itself) or "being confirmed" page (no refresh: it needs the merchant).
     *
     * @param ConfirmationOutcome $outcome
     * @return Page
     */
    private function statusPage(ConfirmationOutcome $outcome): Page
    {
        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->set((string) __('Confirming your payment'));

        $layout = $page->getLayout();
        $block = $layout->getBlock(self::STATUS_BLOCK);
        if ($block instanceof AbstractBlock) {
            $block->setData('status', $outcome->status);
            $block->setData('message', $outcome->message);
            $block->setData('order_reference', (string) $outcome->incrementId);
        }
        if (!$outcome->isInProgress()) {
            $layout->unsetElement(self::REFRESH_BLOCK);
        }

        return $this->noStore($page);
    }

    /**
     * Error message and redirect to the payment step.
     *
     * @param string $message
     * @return Redirect
     */
    private function toPaymentStep(string $message): Redirect
    {
        $this->messageManager->addErrorMessage($message);

        return $this->noStore($this->paymentStep->applyTo($this->redirectFactory->create()));
    }

    /**
     * Redirect that is never cached.
     *
     * @param string $url
     * @param int|null $code
     * @return Redirect
     */
    private function redirect(#[\SensitiveParameter] string $url, ?int $code = null): Redirect
    {
        $redirect = $this->redirectFactory->create();
        $redirect->setUrl($url);
        if ($code !== null) {
            $redirect->setHttpResponseCode($code);
        }

        return $this->noStore($redirect);
    }

    /**
     * This controller's URL for an attempt, with the token only when given.
     *
     * @param string $attemptId
     * @param string $checkoutToken
     * @return string
     */
    private function confirmUrl(
        #[\SensitiveParameter] string $attemptId,
        #[\SensitiveParameter] string $checkoutToken
    ): string {
        $params = ['_secure' => true, '_nosid' => true];
        if (Attempt::isValidId($attemptId)) {
            $params['attempt'] = $attemptId;
        }
        if ($checkoutToken !== '') {
            $params['_query'] = ['checkout_token' => $checkoutToken];
        }

        return $this->url->getUrl(self::ROUTE, $params);
    }

    /**
     * Attempt id from the path.
     *
     * @return string
     */
    private function attemptId(): string
    {
        $value = $this->request->getParam('attempt');

        return is_string($value) ? $value : '';
    }

    /**
     * Token from the query or body (checkout_token; checkout_id as Affirm's alternative name).
     *
     * @return string
     */
    private function checkoutToken(): string
    {
        foreach (self::TOKEN_PARAMS as $name) {
            $value = $this->request->getParam($name);
            if (is_string($value) && trim($value) !== '' && strlen($value) <= self::MAX_TOKEN_LENGTH) {
                return trim($value);
            }
        }

        return '';
    }

    /**
     * Forbid caching (shared caches and the browser's back-forward cache) and referrer leaks of the token.
     *
     * @template T of AbstractResult
     * @param T $result
     * @return T
     */
    private function noStore(AbstractResult $result): AbstractResult
    {
        $result->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0', true);
        $result->setHeader('Pragma', 'no-cache', true);
        $result->setHeader('Referrer-Policy', 'no-referrer', true);

        return $result;
    }
}
