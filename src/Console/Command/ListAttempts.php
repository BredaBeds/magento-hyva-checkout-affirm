<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Console\Command;

use Hyva\CheckoutAffirm\Model\Attempt\Attempt;
use Hyva\CheckoutAffirm\Model\Attempt\AttemptRepositoryInterface;
use Hyva\CheckoutAffirm\Model\Attempt\State;
use Hyva\CheckoutAffirm\Model\ResourceModel\Attempt\Lookup;
use InvalidArgumentException;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * hyva:affirm:attempt:list: the attempts that need reconciliation, or any states on request.
 */
class ListAttempts extends Command
{
    public const NAME = 'hyva:affirm:attempt:list';

    private const OPTION_STATE = 'state';
    private const OPTION_ALL = 'all';
    private const OPTION_LIMIT = 'limit';

    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT = 1000;

    /**
     * @param AttemptRepositoryInterface $attempts
     * @param Lookup $lookup
     */
    public function __construct(
        private readonly AttemptRepositoryInterface $attempts,
        private readonly Lookup $lookup
    ) {
        parent::__construct();
    }

    /**
     * @inheritdoc
     */
    protected function configure(): void
    {
        $this->setName(self::NAME)
            ->setDescription('Lists Affirm checkout attempts (by default those that need reconciliation)')
            ->setHelp(
                'Without options, lists the attempts in placing, authorizing or uncertain, oldest first: these may'
                . ' hold an Affirm authorization and block their cart for other payment methods. Resolve uncertain'
                . ' ones with ' . ResolveAttempt::NAME . '. With --state or --all, lists the newest attempts first.'
                . PHP_EOL . 'Times are UTC.'
            )
            ->addOption(
                self::OPTION_STATE,
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Only attempts in these states (repeat the option or separate with commas): '
                . implode(', ', array_map(static fn (State $state): string => $state->value, State::cases()))
            )
            ->addOption(self::OPTION_ALL, null, InputOption::VALUE_NONE, 'Attempts in every state')
            ->addOption(
                self::OPTION_LIMIT,
                null,
                InputOption::VALUE_REQUIRED,
                'Maximum number of attempts to show (1-' . self::MAX_LIMIT . ')',
                (string) self::DEFAULT_LIMIT
            );
        parent::configure();
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = $this->limit($input->getOption(self::OPTION_LIMIT));
        if ($limit === null) {
            $output->writeln(sprintf('<error>--limit must be a whole number from 1 to %d.</error>', self::MAX_LIMIT));

            return Cli::RETURN_FAILURE;
        }
        try {
            $states = $this->states((array) $input->getOption(self::OPTION_STATE));
        } catch (InvalidArgumentException $exception) {
            $output->writeln('<error>' . OutputFormatter::escape($exception->getMessage()) . '</error>');

            return Cli::RETURN_FAILURE;
        }
        $all = (bool) $input->getOption(self::OPTION_ALL);
        if ($all && $states !== []) {
            $output->writeln('<error>Use either --state or --all, not both.</error>');

            return Cli::RETURN_FAILURE;
        }

        $unresolvedOnly = !$all && $states === [];
        $attempts = $unresolvedOnly
            ? $this->attempts->listUnresolved($limit)
            : $this->lookup->findByStates($states, $limit);

        if ($attempts === []) {
            $output->writeln($unresolvedOnly
                ? '<info>No unresolved attempts: nothing needs reconciliation.</info>'
                : 'No attempts found.');

            return Cli::RETURN_SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders([
            'Attempt ID',
            'State',
            'Store',
            'Quote',
            'Reserved order',
            'Order',
            'Total',
            'Reason',
            'Affirm transaction',
            'Updated (UTC)',
        ]);
        foreach ($attempts as $attempt) {
            $table->addRow(array_map($this->cell(...), [
                $attempt->attemptId,
                $attempt->state->value,
                (string) $attempt->storeId,
                (string) $attempt->quoteId,
                $attempt->reservedIncrementId,
                (string) $attempt->orderIncrementId,
                $this->total($attempt),
                (string) $attempt->failureReason,
                (string) $attempt->providerTransactionId,
                $attempt->updatedAt,
            ]));
        }
        $table->render();

        if (count($attempts) >= $limit) {
            $output->writeln(
                sprintf('<comment>Showing the first %d attempts; use --limit to see more.</comment>', $limit)
            );
        }
        if ($unresolvedOnly) {
            $output->writeln(sprintf(
                'Resolve an uncertain attempt with: bin/magento %s <attempt-id> --outcome=placed --order=<order-number>'
                . ' | --outcome=voided (see bin/magento %s --help).',
                ResolveAttempt::NAME,
                ResolveAttempt::NAME
            ), OutputInterface::OUTPUT_RAW);
        }

        return Cli::RETURN_SUCCESS;
    }

    /**
     * Parse --limit; null when invalid.
     *
     * @param mixed $value
     * @return int|null
     */
    private function limit(mixed $value): ?int
    {
        if (!is_string($value) || !ctype_digit($value)) {
            return null;
        }
        $limit = (int) $value;

        return $limit >= 1 && $limit <= self::MAX_LIMIT ? $limit : null;
    }

    /**
     * Parse the --state values (repeated and/or comma-separated) in the given order, without duplicates.
     *
     * @param array<mixed> $values
     * @return list<State>
     * @throws InvalidArgumentException for an unknown state
     */
    private function states(array $values): array
    {
        $states = [];
        foreach ($values as $value) {
            foreach (explode(',', (string) $value) as $name) {
                $name = strtolower(trim($name));
                if ($name === '') {
                    continue;
                }
                $state = State::tryFrom($name);
                if ($state === null) {
                    throw new InvalidArgumentException(sprintf(
                        'Unknown state "%s". Valid states: %s.',
                        $name,
                        implode(', ', array_map(static fn (State $case): string => $case->value, State::cases()))
                    ));
                }
                $states[$state->value] = $state;
            }
        }

        return array_values($states);
    }

    /**
     * Grand total as "1234.56 USD".
     *
     * @param Attempt $attempt
     * @return string
     */
    private function total(Attempt $attempt): string
    {
        return number_format($attempt->grandTotalCents / 100, 2, '.', '') . ' ' . $attempt->currency;
    }

    /**
     * Table cell: stored values are printed literally (no console markup, no control characters).
     *
     * @param string $value
     * @return string
     */
    private function cell(string $value): string
    {
        return OutputFormatter::escape((string) preg_replace('/[\x00-\x1F\x7F]/', '', $value));
    }
}
