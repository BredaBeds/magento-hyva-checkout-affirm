<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Console\Command;

use Hyva\CheckoutAffirm\Model\Attempt\Attempt;
use Hyva\CheckoutAffirm\Model\Attempt\AttemptRepositoryInterface;
use Hyva\CheckoutAffirm\Model\Attempt\StaleAttemptPolicy;
use Hyva\CheckoutAffirm\Model\Attempt\State;
use Hyva\CheckoutAffirm\Model\Checkout\AttemptRecovery;
use Hyva\CheckoutAffirm\Model\Checkout\OrderPlacement;
use Magento\Framework\Console\Cli;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Sales\Api\Data\OrderInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * hyva:affirm:attempt:resolve: manual reconciliation of an attempt for which Affirm may hold an authorization.
 *
 * The command cannot see Affirm: the operator checks the payment in the Affirm merchant dashboard first. It only
 * validates what Magento knows (the order for "placed", the absence of one for "voided") and resolves the attempt
 * with a compare-and-swap on the state it checked, so an attempt that moves on meanwhile is left alone.
 */
class ResolveAttempt extends Command
{
    public const NAME = 'hyva:affirm:attempt:resolve';

    private const ARGUMENT_ATTEMPT = 'attempt';
    private const OPTION_OUTCOME = 'outcome';
    private const OPTION_ORDER = 'order';
    private const OPTION_FORCE = 'force';

    /**
     * Length of sales_order.increment_id.
     */
    private const ORDER_NUMBER_MAX_LENGTH = 50;

    /**
     * @param AttemptRepositoryInterface $attempts
     * @param OrderPlacement $orderPlacement
     * @param AttemptRecovery $recovery
     * @param DateTime $dateTime
     * @param StaleAttemptPolicy $stalePolicy how long a placing/authorizing attempt must be unchanged before --force
     *        applies (the threshold the escalation cron job uses too)
     */
    public function __construct(
        private readonly AttemptRepositoryInterface $attempts,
        private readonly OrderPlacement $orderPlacement,
        private readonly AttemptRecovery $recovery,
        private readonly DateTime $dateTime,
        private readonly StaleAttemptPolicy $stalePolicy
    ) {
        parent::__construct();
    }

    /**
     * @inheritdoc
     */
    protected function configure(): void
    {
        $this->setName(self::NAME)
            ->setDescription('Resolves an uncertain Affirm checkout attempt after checking the payment at Affirm')
            ->setHelp(OutputFormatter::escape($this->procedure()))
            ->addArgument(
                self::ARGUMENT_ATTEMPT,
                InputArgument::REQUIRED,
                'Attempt ID (64 characters, see ' . ListAttempts::NAME . ')'
            )
            ->addOption(
                self::OPTION_OUTCOME,
                null,
                InputOption::VALUE_REQUIRED,
                '"placed" (an order exists for the attempt) or "voided" (no order; the authorization was voided at'
                . ' Affirm)'
            )
            ->addOption(
                self::OPTION_ORDER,
                null,
                InputOption::VALUE_REQUIRED,
                'Order number (increment ID) of the order placed for the attempt; required with --outcome=placed'
            )
            ->addOption(
                self::OPTION_FORCE,
                null,
                InputOption::VALUE_NONE,
                sprintf(
                    'Also resolve an attempt left in placing or authorizing (a crashed process) that has not changed'
                    . ' for %d minutes',
                    $this->stuckMinutes()
                )
            );
        parent::configure();
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $attemptId = strtolower(trim((string) $input->getArgument(self::ARGUMENT_ATTEMPT)));
        if (!Attempt::isValidId($attemptId)) {
            return $this->refuse(
                $output,
                sprintf('The attempt ID must be 64 hexadecimal characters (see %s).', ListAttempts::NAME)
            );
        }
        $outcome = strtolower(trim((string) $input->getOption(self::OPTION_OUTCOME)));
        if (!in_array(
            $outcome,
            [AttemptRepositoryInterface::OUTCOME_PLACED, AttemptRepositoryInterface::OUTCOME_VOIDED],
            true
        )) {
            return $this->refuse($output, '--outcome must be "placed" or "voided".', true);
        }
        $orderNumber = trim((string) $input->getOption(self::OPTION_ORDER));
        if ($outcome === AttemptRepositoryInterface::OUTCOME_PLACED && $orderNumber === '') {
            return $this->refuse($output, '--outcome=placed needs the order number: --order=<order-number>.', true);
        }
        if ($outcome === AttemptRepositoryInterface::OUTCOME_VOIDED && $orderNumber !== '') {
            return $this->refuse($output, '--order is only used with --outcome=placed.');
        }
        if (strlen($orderNumber) > self::ORDER_NUMBER_MAX_LENGTH) {
            return $this->refuse($output, '--order is not a valid order number.');
        }

        $attempt = $this->attempts->get($attemptId);
        if ($attempt === null) {
            return $this->refuse($output, sprintf('Attempt %s was not found.', $attemptId));
        }
        $refusal = $this->stateRefusal($attempt, (bool) $input->getOption(self::OPTION_FORCE));
        if ($refusal !== null) {
            return $this->refuse($output, $refusal);
        }

        if ($outcome === AttemptRepositoryInterface::OUTCOME_PLACED) {
            $order = $this->orderPlacement->findOrder($orderNumber, $attempt->storeId);
            if ($order === null) {
                return $this->refuse($output, sprintf(
                    'No order #%s exists in store %d, the store of the attempt.',
                    $orderNumber,
                    $attempt->storeId
                ));
            }
            $mismatch = $this->mismatch($order, $attempt);
            if ($mismatch !== null) {
                return $this->refuse($output, $mismatch);
            }
            $orderId = (int) $order->getEntityId();
            $orderNumber = (string) $order->getIncrementId();
            $set = [
                'failure_reason' => Attempt::REASON_RESOLVED_PLACED,
                'order_increment_id' => $orderNumber,
                'order_id' => $orderId > 0 ? $orderId : null,
            ];
        } else {
            $order = $this->orderPlacement->findOrder($attempt->reservedIncrementId, $attempt->storeId);
            if ($order !== null && $this->mismatch($order, $attempt) === null) {
                return $this->refuse($output, sprintf(
                    'Order #%1$s was placed with %2$s for this attempt. Resolve the attempt as placed instead:'
                    . ' --outcome=placed --order=%1$s',
                    (string) $order->getIncrementId(),
                    $attempt->paymentMethod
                ));
            }
            $set = ['failure_reason' => Attempt::REASON_RESOLVED_VOIDED];
        }

        // Compare-and-swap on the state checked above: an attempt that moved on meanwhile is not resolved.
        if (!$this->attempts->transition($attempt->attemptId, [$attempt->state], State::RESOLVED, $set)) {
            return $this->refuse($output, sprintf(
                'The attempt changed while it was being resolved. Check it again with %s.',
                ListAttempts::NAME
            ));
        }

        if ($outcome === AttemptRepositoryInterface::OUTCOME_PLACED) {
            // As the escalation job does: a placement that died after saving the order may have left the cart active.
            $this->orderPlacement->deactivateQuoteById($attempt->quoteId);
        }

        $this->reportResolved($output, $attempt, $outcome, $orderNumber);

        return Cli::RETURN_SUCCESS;
    }

    /**
     * Why the attempt cannot be resolved in its current state, or null when it can.
     *
     * @param Attempt $attempt
     * @param bool $force
     * @return string|null
     */
    private function stateRefusal(Attempt $attempt, bool $force): ?string
    {
        return match ($attempt->state) {
            State::UNCERTAIN => null,
            State::PLACING, State::AUTHORIZING => $this->stuckRefusal($attempt, $force),
            State::PLACED => sprintf(
                'The attempt was placed (order #%s); there is nothing to resolve.',
                (string) $attempt->orderIncrementId
            ),
            State::RESOLVED => sprintf(
                'The attempt is already resolved (%s).',
                (string) ($attempt->failureReason ?? State::RESOLVED->value)
            ),
            default => sprintf(
                'Only uncertain attempts can be resolved; this attempt is %s and holds no Affirm authorization'
                . ' without an order.',
                $attempt->state->value
            ),
        };
    }

    /**
     * Placing/authorizing attempts are resolved only with --force once unchanged for long enough.
     *
     * @param Attempt $attempt
     * @param bool $force
     * @return string|null
     */
    private function stuckRefusal(Attempt $attempt, bool $force): ?string
    {
        if (!$force) {
            return sprintf(
                'The attempt is %s: an order placement may still be running. If the process died, re-run with'
                . ' --force once the attempt has not changed for %d minutes.',
                $attempt->state->value,
                $this->stuckMinutes()
            );
        }
        $updatedAt = $attempt->updatedAtTimestamp();
        if ($updatedAt === null) {
            return 'The time of the last change of the attempt cannot be read, so --force cannot be applied.';
        }
        $now = (int) $this->dateTime->gmtTimestamp();
        if (!$this->stalePolicy->isStale($attempt, $now)) {
            $age = $now - $updatedAt;

            return sprintf(
                'The attempt changed %d minute(s) ago; an order placement may still be running. Try again once it'
                . ' has not changed for %d minutes.',
                intdiv(max(0, $age), 60),
                $this->stuckMinutes()
            );
        }

        return null;
    }

    /**
     * Why an order does not belong to the attempt, or null when it does.
     *
     * @param OrderInterface $order
     * @param Attempt $attempt
     * @return string|null
     */
    private function mismatch(OrderInterface $order, Attempt $attempt): ?string
    {
        if ((int) $order->getQuoteId() !== $attempt->quoteId) {
            return sprintf(
                'Order #%s was not placed from the cart of this attempt (quote %d).',
                (string) $order->getIncrementId(),
                $attempt->quoteId
            );
        }
        $method = (string) $order->getPayment()?->getMethod();
        if ($method !== $attempt->paymentMethod) {
            return sprintf(
                'Order #%s was paid with "%s", not with %s. If Affirm authorized this attempt, void the'
                . ' authorization at Affirm and resolve the attempt as voided.',
                (string) $order->getIncrementId(),
                $method,
                $attempt->paymentMethod
            );
        }
        if (!$this->recovery->belongsTo($order, $attempt)) {
            return sprintf(
                'Order #%s was placed from this cart with %s, but by another checkout attempt. Resolve that attempt'
                . ' instead; if Affirm authorized this one as well, void it at Affirm and resolve it as voided.',
                (string) $order->getIncrementId(),
                $attempt->paymentMethod
            );
        }

        return null;
    }

    /**
     * Confirmation and the follow-up the operator owns.
     *
     * @param OutputInterface $output
     * @param Attempt $attempt
     * @param string $outcome
     * @param string $orderNumber
     * @return void
     */
    private function reportResolved(
        OutputInterface $output,
        Attempt $attempt,
        string $outcome,
        string $orderNumber
    ): void {
        if ($outcome === AttemptRepositoryInterface::OUTCOME_PLACED) {
            $output->writeln(OutputFormatter::escape(sprintf(
                'Attempt %s resolved as placed with order #%s.',
                $attempt->attemptId,
                $orderNumber
            )));
            if ($orderNumber !== $attempt->reservedIncrementId) {
                $output->writeln('<comment>' . OutputFormatter::escape(sprintf(
                    'Note: the attempt had reserved order number #%s.',
                    $attempt->reservedIncrementId
                )) . '</comment>');
            }

            return;
        }

        $output->writeln(OutputFormatter::escape(sprintf(
            'Attempt %s resolved as voided. The cart (quote %d) is no longer blocked for other payment methods.',
            $attempt->attemptId,
            $attempt->quoteId
        )));
        $output->writeln('<comment>' . OutputFormatter::escape(sprintf(
            'Make sure no authorization remains at Affirm for order reference #%s%s.',
            $attempt->reservedIncrementId,
            $attempt->providerTransactionId !== null
                ? sprintf(' (Affirm transaction %s)', $attempt->providerTransactionId)
                : ''
        )) . '</comment>');
    }

    /**
     * Print an error, optionally followed by the procedure.
     *
     * @param OutputInterface $output
     * @param string $message
     * @param bool $withProcedure
     * @return int
     */
    private function refuse(OutputInterface $output, string $message, bool $withProcedure = false): int
    {
        $output->writeln('<error>' . OutputFormatter::escape($message) . '</error>');
        if ($withProcedure) {
            $output->writeln('');
            $output->writeln($this->procedure(), OutputInterface::OUTPUT_RAW);
        }

        return Cli::RETURN_FAILURE;
    }

    /**
     * The reconciliation procedure, shown by --help and after an invalid outcome.
     *
     * @return string
     */
    private function procedure(): string
    {
        return implode(PHP_EOL, [
            'Reconciliation procedure:',
            '  1. Find the attempt (bin/magento ' . ListAttempts::NAME . ') and any order with its reserved order'
            . ' number.',
            '  2. Look up the payment in the Affirm merchant dashboard by that order number or the Affirm'
            . ' transaction ID.',
            '  3. If an order exists, resolve the attempt as placed:',
            '       bin/magento ' . self::NAME . ' <attempt-id> --outcome=placed --order=<order-number>',
            '  4. If no order exists, void any authorization in the Affirm dashboard, then resolve the attempt as'
            . ' voided:',
            '       bin/magento ' . self::NAME . ' <attempt-id> --outcome=voided',
            '     This releases the cart for other payment methods.',
            'Only uncertain attempts can be resolved. An attempt left in placing or authorizing by a crashed process'
            . sprintf(
                ' is settled by the cron job hyva_affirm_attempt_escalate (linked to its order, or made uncertain)'
                . ' once it has not changed for %d minutes; without cron, resolve it with --force after that time.',
                $this->stuckMinutes()
            ),
        ]);
    }

    /**
     * The --force threshold in whole minutes (at least one).
     *
     * @return int
     */
    private function stuckMinutes(): int
    {
        return max(1, intdiv($this->stalePolicy->staleAfterSeconds(), 60));
    }
}
