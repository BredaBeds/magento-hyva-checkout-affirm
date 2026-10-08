<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Console\Command;

use Hyva\CheckoutAffirm\Cron\CleanupAttempts;
use Hyva\CheckoutAffirm\Model\Attempt\AttemptRepositoryInterface;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * hyva:affirm:attempt:prune: the deletion step of the daily cleanup, with a chosen retention.
 */
class PruneAttempts extends Command
{
    public const NAME = 'hyva:affirm:attempt:prune';

    private const OPTION_DAYS = 'days';

    /**
     * @param AttemptRepositoryInterface $attempts
     */
    public function __construct(
        private readonly AttemptRepositoryInterface $attempts
    ) {
        parent::__construct();
    }

    /**
     * @inheritdoc
     */
    protected function configure(): void
    {
        $this->setName(self::NAME)
            ->setDescription('Deletes old failed, resolved and expired Affirm checkout attempts')
            ->setHelp(
                'Deletes failed and resolved attempts not updated for the given number of days, and initiated or'
                . ' confirmed attempts that expired more than that many days ago. Attempts in placing, authorizing,'
                . ' uncertain or placed are never deleted. The cron job hyva_affirm_attempt_cleanup does this daily'
                . sprintf(' with %d days.', CleanupAttempts::DEFAULT_RETENTION_DAYS)
            )
            ->addOption(
                self::OPTION_DAYS,
                null,
                InputOption::VALUE_REQUIRED,
                'Keep attempts younger than this many days (at least 1)',
                (string) CleanupAttempts::DEFAULT_RETENTION_DAYS
            );
        parent::configure();
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $days = $input->getOption(self::OPTION_DAYS);
        if (!is_string($days) || !ctype_digit($days) || (int) $days < 1) {
            $output->writeln('<error>--days must be a whole number of at least 1.</error>');

            return Cli::RETURN_FAILURE;
        }

        $deleted = $this->attempts->prune((int) $days);
        $output->writeln(sprintf('Deleted %d attempt(s) older than %d day(s).', $deleted, (int) $days));

        return Cli::RETURN_SUCCESS;
    }
}
