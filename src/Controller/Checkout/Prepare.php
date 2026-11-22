<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Controller\Checkout;

use Hyva\CheckoutAffirm\Model\Checkout\Preparation;
use Hyva\CheckoutAffirm\ViewModel\Method;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

/**
 * Place Order with Affirm: POST hyva_affirm/checkout/prepare with the form key.
 *
 * Prepares the Affirm checkout of the session's cart and answers with JSON: {checkout, attemptId, mode} for the
 * browser to open Affirm with, or {error} with a shopper-safe message. A plain request rather than a Magewire
 * action, because Place Order can sit on a later checkout step than the payment method's component.
 *
 * The form key is checked here: Magento's own CSRF check skips it for requests sent with X-Requested-With, and this
 * one writes (an attempt, the reserved order number).
 */
class Prepare implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public const ROUTE = 'hyva_affirm/checkout/prepare';

    /**
     * @param CheckoutSession $checkoutSession
     * @param Preparation $preparation
     * @param Method $method
     * @param JsonFactory $jsonFactory
     * @param LoggerInterface $logger
     * @param FormKeyValidator $formKeyValidator
     */
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly Preparation $preparation,
        private readonly Method $method,
        private readonly JsonFactory $jsonFactory,
        private readonly LoggerInterface $logger,
        private readonly FormKeyValidator $formKeyValidator
    ) {
    }

    /**
     * The form key decides, for every request.
     *
     * @param RequestInterface $request
     * @return bool
     */
    public function validateForCsrf(RequestInterface $request): bool
    {
        return $this->formKeyValidator->validate($request);
    }

    /**
     * A refused request gets the JSON answer the page can show.
     *
     * @param RequestInterface $request
     * @return InvalidRequestException
     */
    public function createCsrfValidationException(RequestInterface $request): InvalidRequestException
    {
        return new InvalidRequestException($this->result(
            ['error' => (string) __('Your session has expired. Please reload the page and place the order again.')],
            403
        ));
    }

    /**
     * Prepare the checkout; never throws.
     *
     * @return Json
     */
    public function execute(): Json
    {
        try {
            $mode = $this->method->getMode();
            [$checkout, $attempt] = $this->preparation->prepare($this->checkoutSession->getQuote(), $mode);
            $data = ['checkout' => $checkout, 'attemptId' => $attempt->attemptId, 'mode' => $mode];
        } catch (LocalizedException $e) {
            $data = ['error' => $e->getMessage()];
        } catch (\Throwable $e) {
            // By class and location only: messages can carry customer data.
            $this->logger->error('Hyva_CheckoutAffirm: the Affirm checkout could not be prepared.', [
                'exception' => get_class($e),
                'location' => $e->getFile() . ':' . $e->getLine(),
            ]);
            $data = ['error' => (string) __(
                'Affirm is not available right now. Please try again or choose another payment method.'
            )];
        }

        return $this->result($data);
    }

    /**
     * Uncached JSON answer.
     *
     * @param array<string,mixed> $data
     * @param int $status
     * @return Json
     */
    private function result(array $data, int $status = 200): Json
    {
        $result = $this->jsonFactory->create();
        $result->setHttpResponseCode($status);
        $result->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0', true);
        $result->setData($data);

        return $result;
    }
}
