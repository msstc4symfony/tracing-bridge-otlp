<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Test\Unit\Infrastructure\Profiling;

use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Profiling\ReportedNames;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReportedNames::class)]
final class ReportedNamesTest extends TestCase
{
    public function testANameIsFirstOnceUntilReset(): void
    {
        $names = new ReportedNames();

        self::assertSame([true, false, true], [$names->first('a'), $names->first('a'), $names->first('b')]);

        $names->reset();

        self::assertTrue($names->first('a'));
    }

    public function testNamesPastTheLimitAreNotRemembered(): void
    {
        $names = new ReportedNames();
        for ($i = 0; $i < ReportedNames::MAX; $i++) {
            self::assertTrue($names->first('name ' . $i));
        }

        self::assertTrue($names->first('one too many'));
        self::assertTrue($names->first('one too many'));
        self::assertFalse($names->first('name 0'));
    }
}
