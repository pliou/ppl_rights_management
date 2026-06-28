<?php

declare(strict_types=1);

namespace Ppl\PplRightsManagement\Command;

use Ppl\PplRightsManagement\Service\HistoryAuditOutbox;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Replays rights-management audit (history) entries that a database outage queued to the outbox.
 *
 * A privileged rights change always succeeds first; if its audit row cannot be written at that
 * moment it is parked in {@see HistoryAuditOutbox} instead of being silently lost. Opening the
 * History tab flushes the queue opportunistically; this command is the scheduler-friendly path
 * (e.g. a periodic CLI/Scheduler task) so the audit trail self-heals without an admin present.
 */
#[AsCommand(
    name: 'ppl:rights:flush-audit-outbox',
    description: 'Replay any rights-management audit (history) entries that a database outage queued to the outbox.'
)]
final class FlushAuditOutboxCommand extends Command
{
    public function __construct(
        private readonly HistoryAuditOutbox $outbox
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pendingBefore = $this->outbox->pendingCount();
        if ($pendingBefore === 0) {
            $output->writeln('<info>Audit outbox is empty - nothing to recover.</info>');
            return Command::SUCCESS;
        }

        $recovered = $this->outbox->flush();
        $remaining = $this->outbox->pendingCount();

        $output->writeln(sprintf(
            'Audit outbox: %d pending, %d recovered into the history table, %d still queued.',
            $pendingBefore,
            $recovered,
            $remaining
        ));

        if ($remaining > 0) {
            $output->writeln('<comment>Some entries could not be written yet (database still unavailable?). '
                . 'They remain queued for the next run.</comment>');
            return Command::FAILURE;
        }

        $output->writeln('<info>All queued audit entries recovered.</info>');
        return Command::SUCCESS;
    }
}
