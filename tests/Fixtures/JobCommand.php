<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Test\Fixtures;

use Msstc4Symfony\ProfilingBundle\Application\ProfilingFactoryInterface;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('app:job')]
final class JobCommand extends Command
{
    public function __construct(
        private readonly ProfilingFactoryInterface $profiling,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->profiling->endSpan($this->profiling->createSpan('command work'));

        return Command::SUCCESS;
    }
}
