<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp;

use OpenTelemetry\API\Signals;
use OpenTelemetry\SDK\Common\Configuration\Configuration;
use OpenTelemetry\SDK\Common\Configuration\Variables;
use Throwable;

/**
 * Longest string attribute value: one huge value would push the batch of the unit of work over the receiver's limit,
 * and the whole batch would be lost.
 *
 * @internal
 */
final class AttributeLimit
{
    public const DEFAULT_VALUE_LENGTH = 4096;

    private const VARIABLES = [
        Signals::TRACE => [Variables::OTEL_SPAN_ATTRIBUTE_VALUE_LENGTH_LIMIT, Variables::OTEL_ATTRIBUTE_VALUE_LENGTH_LIMIT],
        Signals::LOGS => [Variables::OTEL_LOGRECORD_ATTRIBUTE_VALUE_LENGTH_LIMIT, Variables::OTEL_ATTRIBUTE_VALUE_LENGTH_LIMIT],
    ];

    private function __construct()
    {
    }

    /**
     * @param Signals::TRACE|Signals::LOGS $signal
     *
     * @return positive-int characters (bytes for a string that is not UTF-8)
     */
    public static function valueLength(string $signal = Signals::TRACE): int
    {
        foreach (self::VARIABLES[$signal] as $variable) {
            if (!Configuration::has($variable)) {
                continue;
            }

            try {
                $limit = Configuration::getInt($variable);
            } catch (Throwable) {
                continue;
            }

            if ($limit > 0) {
                return $limit;
            }
        }

        return self::DEFAULT_VALUE_LENGTH;
    }
}
