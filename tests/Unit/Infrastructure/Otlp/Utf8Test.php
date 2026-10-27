<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Test\Unit\Infrastructure\Otlp;

use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp\Utf8;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

#[CoversClass(Utf8::class)]
final class Utf8Test extends TestCase
{
    #[TestWith(['plain', true])]
    #[TestWith(['café', true])]
    #[TestWith(["caf\xE9", false])]
    #[TestWith(['', true])]
    public function testTellsUtf8FromOtherBytes(string $value, bool $valid): void
    {
        self::assertSame($valid, Utf8::isValid($value));
    }

    public function testReplacesTheBytesOfAStringThatIsNotUtf8(): void
    {
        self::assertSame('caf? ok', Utf8::valid("caf\xE9 ok"));
        self::assertSame('café', Utf8::valid('café'));
    }

    /**
     * @param positive-int $limit
     */
    #[TestWith(['abcd', 4, 'abcd'])]
    #[TestWith(['abcde', 4, 'abcd'])]
    #[TestWith(['éééé', 3, 'ééé'])]
    #[TestWith(['éé', 3, 'éé'])]
    #[TestWith(["\xE9\xE9\xE9\xE9", 2, "\xE9\xE9"])]
    public function testCutsCharactersOfUtf8AndBytesOfTheRest(string $value, int $limit, string $cut): void
    {
        self::assertSame($cut, Utf8::cut($value, $limit));
    }
}
