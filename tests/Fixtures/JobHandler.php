<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Test\Fixtures;

use Msstc4Symfony\ProfilingBundle\Application\ProfilingFactoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class JobHandler
{
    public function __construct(
        private ProfilingFactoryInterface $profiling,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(Job $job): void
    {
        $span = $this->profiling->createSpan('job work');
        $this->logger->info('inside job');
        $this->profiling->endSpan($span);
    }
}
