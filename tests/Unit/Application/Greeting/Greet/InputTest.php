<?php

declare(strict_types=1);

namespace Msstc4Symfony\SkeletonBundle\Test\Unit\Application\Greeting\Greet;

use InvalidArgumentException;
use Msstc4Symfony\SkeletonBundle\Application\Greeting\Greet\Input;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class InputTest extends TestCase
{
    public function testNameIsTrimmed(): void
    {
        self::assertSame('Ada', (new Input(' Ada '))->name);
    }

    #[TestWith([''])]
    #[TestWith(['   '])]
    public function testBlankNameIsRejected(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Input($name);
    }
}
