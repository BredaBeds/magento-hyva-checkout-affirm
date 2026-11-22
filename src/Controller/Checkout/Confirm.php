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
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
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
 * checkout_confirm handle) or the payment step with a message. The in-progress page asks again in the background
 * with ?poll=1: that request runs the pipeline too and answers only whether to keep waiting (JSON), so the page is
 * never reloaded under the shopper while it waits (WCAG 2.2.1). A POST (Affirm's default action) changes nothing:
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
    public const POLL_PARAM = 'poll';

    private const SUCCESS_ROUTE = 'checkout/onepage/success';
    private const TOKEN_PARAMS = ['checkout_token', 'checkout_id'];

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
     * @param JsonFactory $jsonFactory
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
        private readonly LoggerInterface $logger,
        private readonly JsonFactory $jsonFactory
    ) {
    }

    /**
     * Confirm the attempt (GET) or bounce a POST to the GET URL.
     *
     * @return ResultInterface a redirect, the in-progress page, or the answer to the page's status check
     */
    public function execute(): ResultInterface
    {
        $attemptId = $this->attemptId();
        $checkoutToken = $this->checkoutToken();

        if ($this->request->isPost()) {
            // No session is available here (see Plugin\Framework\Session\SkipSessionOnConfirmPost): change nothing.
            return $this->redirect($this->confirmUrl($attemptId, $checkoutToken), 303);
        }
        // The in-progress page's status check: everything that ends the wait (the receipt, a message, the page that
        // waits for the merchant) is left to the ordinary request the page makes next, so nothing happens twice.
        $poll = $this->request->getParam(self::POLL_PARAM) === '1';
        if (!Attempt::isValidId($attemptId)) {
            return $poll ? $this->pollResult(false) : $this->toPaymentStep(
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

            return $poll
                ? $this->pollResult(false)
                : $this->toPaymentStep((string) __('We could not confirm your Affirm payment. Please try again.'));
        }

        if ($poll) {
            return $this->pollResult($outcome->isInProgress());
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
            // the attempt is in progress the page checks again with the token, because a placement that hands the
            // attempt back (cart locked, Affirm unreachable) needs it again.
            return $this->redirect($this->confirmUrl($attemptId, ''));
        }

        return $this->statusPage($outcome, $this->confirmUrl($attemptId, $checkoutToken));
    }

    /**
     * @inheritdoc
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
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
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    /**
     * In-progress page (checks again in the background) or "being confirmed" page (no check: it needs the merchant).
     *
     * @param ConfirmationOutcome $outcome
     * @param string $retryUrl this request's URL, for the page's "Check now" link
     * @return Page
     */
    private function statusPage(ConfirmationOutcome $outcome, #[\SensitiveParameter] string $retryUrl): Page
    {
        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->set((string) __('Confirming your payment'));

        $layout = $page->getLayout();
        $block = $layout->getBlock(self::STATUS_BLOCK);
        if ($block instanceof AbstractBlock) {
            $block->setData('status', $outcome->status);
            $block->setData('message', $outcome->message);
            $block->setData('order_reference', (string) $outcome->incrementId);
            $block->setData('retry_url', $retryUrl);
        }
        if (!$outcome->isInProgress()) {
            $layout->unsetElement(self::REFRESH_BLOCK);
        }

        return $this->noStore($page);
    }

    /**
     * Answer to the in-progress page's status check: whether to keep waiting.
     *
     * @param bool $inProgress
     * @return Json
     */
    private function pollResult(bool $inProgress): Json
    {
        return $this->noStore($this->jsonFactory->create()->setData(['in_progress' => $inProgress]));
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
     * Only a value with the shape of a token counts; anything else is never put back into a URL.
     *
     * @return string
     */
    private function checkoutToken(): string
    {
        foreach (self::TOKEN_PARAMS as $name) {
            $value = $this->request->getParam($name);
            $value = is_string($value) ? trim($value) : '';
            if (Attempt::isValidToken($value)) {
                return $value;
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
