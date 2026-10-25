<?php

declare(strict_types=1);

namespace Msstc4Symfony\SkeletonBundle\Application\Greeting\Greet;

final readonly class Action
{
    /**
     * @param non-empty-string $greeting
     */
    public function __construct(private string $greeting)
    {
    }

    public function __invoke(Input $input): Output
    {
        return new Output(sprintf('%s, %s!', $this->greeting, $input->name));
    }
}
