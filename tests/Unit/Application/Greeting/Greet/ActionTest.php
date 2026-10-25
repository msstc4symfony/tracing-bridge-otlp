<?php

declare(strict_types=1);

namespace Msstc4Symfony\SkeletonBundle\Test\Unit\Application\Greeting\Greet;

use Msstc4Symfony\SkeletonBundle\Application\Greeting\Greet\Action;
use Msstc4Symfony\SkeletonBundle\Application\Greeting\Greet\Input;
use PHPUnit\Framework\TestCase;

final class ActionTest extends TestCase
{
    public function testGreetsWithTheConfiguredGreeting(): void
    {
        self::assertSame('Hi, Ada!', (new Action('Hi'))(new Input('Ada'))->message);
    }
}
