<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Test\Fixtures;

use Msstc4Symfony\ProfilingBundle\Application\ProfilingFactoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class TracedController
{
    public function __construct(
        private ProfilingFactoryInterface $profiling,
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(): Response
    {
        $span = $this->profiling->createSpan('work');
        $this->logger->info('inside work');
        $this->httpClient->request('GET', 'http://remote.example/pay')->getStatusCode();
        $this->profiling->endSpan($span);

        return new Response('ok', Response::HTTP_CREATED);
    }
}
