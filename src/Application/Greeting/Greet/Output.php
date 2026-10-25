<?php

declare(strict_types=1);

namespace Msstc4Symfony\SkeletonBundle\Application\Greeting\Greet;

final readonly class Output
{
    /**
     * @param non-empty-string $message
     */
    public function __construct(public string $message)
    {
    }
}
