<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 *
 * Test double written from the public API for CI only; not the original code; MIT.
 *
 * Stands in for the classes of hyva-themes/magento2-hyva-checkout (public signatures as of 1.3.14) that this
 * module's code and unit tests use, so CI runs without Hyvä packages. Only the members used here are declared,
 * with the same names, parameter types and return types. Evaluation results, order data and the place-order
 * service defaults keep their plain behaviour (tests use real instances or subclasses); methods that need a
 * Magento runtime (object manager, configuration) throw: unit tests mock them.
 */
declare(strict_types=1);

namespace Hyva\Checkout\Exception {
    class CheckoutNotFoundException extends \Exception
    {
    }
}

namespace Hyva\Checkout\Model {
    use Hyva\Checkout\Exception\CheckoutNotFoundException;

    class Config
    {
        /**
         * @throws CheckoutNotFoundException
         */
        public function getActiveCheckoutData(): array
        {
            throw new \LogicException('Test double: mock ' . __METHOD__ . '().');
        }
    }
}

namespace Hyva\Checkout\Model\ConfigData\HyvaThemes\Developer {
    class SystemConfigEvaluationApi
    {
    }
}

namespace Hyva\Checkout\Model\Magewire\Component {
    use Hyva\Checkout\Model\Magewire\Component\Evaluation\ErrorEventMessage;
    use Hyva\Checkout\Model\Magewire\Component\Evaluation\Success;
    use Magewirephp\Magewire\Component;

    interface EvaluationResultInterface
    {
        public function getArguments(Component $component): array;

        public function getResult(): bool;

        public function getType(): string;

        public function isBlocking(): bool;
    }

    interface EvaluationInterface
    {
        public function evaluateCompletion(EvaluationResultFactory $resultFactory): EvaluationResultInterface;
    }

    class EvaluationResultFactory
    {
        public function createSuccess(array $details = [], string|null $event = null): Success
        {
            throw new \LogicException('Test double: mock ' . __METHOD__ . '().');
        }

        public function createErrorMessageEvent(
            string|null $text = null,
            string|null $event = null,
            string $messageType = 'error',
            bool $blocking = false
        ): ErrorEventMessage {
            throw new \LogicException('Test double: mock ' . __METHOD__ . '().');
        }
    }
}

namespace Hyva\Checkout\Model\Magewire\Component\Evaluation {
    use Hyva\Checkout\Model\ConfigData\HyvaThemes\Developer\SystemConfigEvaluationApi;
    use Hyva\Checkout\Model\Magewire\Component\EvaluationResultInterface;
    use Magewirephp\Magewire\Component;

    abstract class EvaluationResult implements EvaluationResultInterface
    {
        public const TYPE = 'abstract';

        abstract public function getArguments(Component $component): array;

        public function getType(): string
        {
            return $this::TYPE;
        }

        public function getResult(): bool
        {
            return true;
        }

        public function isBlocking(): bool
        {
            return false;
        }
    }

    class Event extends EvaluationResult
    {
        public const TYPE = 'event';
        public const EVENT = 'evaluation:event:default';

        private ?string $event = null;

        public function getArguments(Component $component): array
        {
            return ['event' => $this->getEvent()];
        }

        public function withCustomEvent(string $name): self
        {
            $this->event = $name;

            return $this;
        }

        public function getEvent(): string
        {
            return $this->event ?? $this::EVENT;
        }
    }

    class Success extends Event
    {
        public const EVENT = 'evaluation:event:success';
    }

    class ErrorEvent extends Event
    {
        public const EVENT = 'evaluation:event:error';

        public function getResult(): bool
        {
            return false;
        }
    }

    class ErrorEventMessage extends ErrorEvent
    {
        private string $messageText = 'Something went wrong.';

        public function __construct(
            SystemConfigEvaluationApi $systemConfigEvaluationApi
        ) {
        }

        public function withMessage(string $message): self
        {
            // The original capitalises the first letter as well.
            $this->messageText = ucfirst($message);

            return $this;
        }

        public function getMessage(): string
        {
            return $this->messageText;
        }
    }
}

namespace Hyva\Checkout\Model\Magewire\Payment {
    use Exception;
    use Hyva\Checkout\Model\Magewire\Component\EvaluationInterface;
    use Hyva\Checkout\Model\Magewire\Component\EvaluationResultFactory;
    use Hyva\Checkout\Model\Magewire\Component\EvaluationResultInterface;
    use Magento\Framework\DataObject;
    use Magento\Quote\Api\CartManagementInterface;
    use Magento\Quote\Model\Quote;
    use Magewirephp\Magewire\Component;

    abstract class AbstractOrderData extends DataObject
    {
        public function getPayment(): array
        {
            // A non-array "payment" entry is a TypeError, as in the original.
            return $this->getData('payment') ?? [];
        }
    }

    class DefaultOrderData extends AbstractOrderData
    {
    }

    interface PlaceOrderServiceInterface
    {
        public function placeOrder(Quote $quote): int;

        public function canPlaceOrder(): bool;

        public function handleException(Exception $exception, Component $component, Quote $quote): void;

        public function canRedirect(): bool;

        public function getRedirectUrl(Quote $quote, ?int $orderId = null): string;
    }

    abstract class AbstractPlaceOrderService implements PlaceOrderServiceInterface, EvaluationInterface
    {
        public const REDIRECT_PATH = 'checkout/onepage/success';

        protected CartManagementInterface $cartManagement;
        protected AbstractOrderData $orderData;

        public function __construct(
            CartManagementInterface $cartManagement,
            ?AbstractOrderData $orderData = null
        ) {
            $this->cartManagement = $cartManagement;
            $this->orderData = $orderData ?? new DefaultOrderData();
        }

        public function placeOrder(Quote $quote): int
        {
            return (int) $this->cartManagement->placeOrder($quote->getId(), $quote->getPayment());
        }

        public function canPlaceOrder(): bool
        {
            return true;
        }

        public function handleException(Exception $exception, Component $component, Quote $quote): void
        {
            throw $exception;
        }

        public function canRedirect(): bool
        {
            return true;
        }

        public function getRedirectUrl(Quote $quote, ?int $orderId = null): string
        {
            $url = (string) ($quote->getPayment()->getOrderPlaceRedirectUrl() ?? '');

            return $url !== '' ? $url : self::REDIRECT_PATH;
        }

        public function evaluateCompletion(
            EvaluationResultFactory $resultFactory,
            int|null $orderId = null
        ): EvaluationResultInterface {
            return $resultFactory->createSuccess();
        }

        public function getData()
        {
            return $this->orderData;
        }
    }
}
