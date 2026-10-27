<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp;

/**
 * The OTLP encoder throws on a name, key or string body that is not UTF-8, which would lose the whole batch.
 *
 * @internal
 */
final class Utf8
{
    private function __construct()
    {
    }

    public static function isValid(string $value): bool
    {
        return preg_match('//u', $value) === 1;
    }

    /**
     * Every byte that is not ASCII becomes "?" in a string that is not UTF-8.
     */
    public static function valid(string $value): string
    {
        return self::isValid($value) ? $value : (string) preg_replace('/[\x80-\xFF]/', '?', $value);
    }

    /**
     * Characters of a UTF-8 string, as the SDK counts the limit; bytes of anything else.
     *
     * @param positive-int $limit
     */
    public static function cut(string $value, int $limit): string
    {
        // A string has no more characters than bytes: a short one needs no scan.
        if (strlen($value) <= $limit) {
            return $value;
        }

        return self::isValid($value) ? mb_substr($value, 0, $limit, 'UTF-8') : substr($value, 0, $limit);
    }
}
