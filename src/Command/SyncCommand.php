<?php

declare(strict_types=1);

namespace Everysize\Checkout\Command;

use Everysize\Checkout\Sync;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * everysize:sync — import and report back in one run; the command for the
 * cron job (every 5 minutes). everysize:order:import and
 * everysize:order:export run one half, e.g. for testing.
 */
class SyncCommand extends Command
{
    public const MODE_ALL = 'all';
    public const MODE_IMPORT = 'import';
    public const MODE_EXPORT = 'export';

    private Sync $sync;
    private string $mode;

    public function __construct(Sync $sync, string $mode = self::MODE_ALL)
    {
        $this->sync = $sync;
        $this->mode = $mode;
        parent::__construct();
    }

    protected function configure(): void
    {
        $descriptions = [
            self::MODE_ALL => 'Import new everysize orders and report shipping, cancellations and returns',
            self::MODE_IMPORT => 'Import new everysize orders and report their order numbers',
            self::MODE_EXPORT => 'Report shipping, cancellations and returns to everysize',
        ];
        $this->setDescription($descriptions[$this->mode]);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->sync->run($this->mode !== self::MODE_EXPORT, $this->mode !== self::MODE_IMPORT);

        foreach ($result['messages'] as $message) {
            $output->writeln($message);
        }
        foreach ($result['errors'] as $error) {
            $output->writeln('<error>'.$error.'</error>');
        }

        return $result['errors'] === [] ? 0 : 1;
    }
}
