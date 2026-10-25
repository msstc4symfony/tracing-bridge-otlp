<?php

declare(strict_types=1);

namespace Msstc4Symfony\SkeletonBundle\Application\Greeting\Greet;

use InvalidArgumentException;

final readonly class Input
{
    /** @var non-empty-string */
    public string $name;

    public function __construct(string $name)
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('The name must not be blank.');
        }

        $this->name = $name;
    }
}
