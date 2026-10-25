<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp;

use Composer\InstalledVersions;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScope;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeInterface;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;

/**
 * The resource (the service) and instrumentation scope (this bundle) of every exported span.
 *
 * @internal
 */
final class ResourceFactory
{
    public const PACKAGE = 'msstc4symfony/tracing-bridge-otlp';

    private const SERVICE_NAME = 'service.name';

    // What the SDK reports when neither OTEL_SERVICE_NAME nor OTEL_RESOURCE_ATTRIBUTES names the service.
    private const UNKNOWN_SERVICE = 'unknown_service';

    private function __construct()
    {
    }

    /**
     * @param non-empty-string|null $serviceName null: OTEL_SERVICE_NAME / OTEL_RESOURCE_ATTRIBUTES, else the project directory
     */
    public static function resource(?string $serviceName, string $projectDir): ResourceInfo
    {
        $resource = ResourceInfoFactory::defaultResource();
        $detected = $resource->getAttributes()->get(self::SERVICE_NAME);
        if ($serviceName === null && is_string($detected) && $detected !== '' && !str_starts_with($detected, self::UNKNOWN_SERVICE)) {
            return $resource;
        }

        return $resource->merge(ResourceInfo::create(Attributes::create([self::SERVICE_NAME => $serviceName ?? basename($projectDir)])));
    }

    public static function scope(): InstrumentationScopeInterface
    {
        $version = InstalledVersions::isInstalled(self::PACKAGE) ? InstalledVersions::getPrettyVersion(self::PACKAGE) : null;

        return new InstrumentationScope(self::PACKAGE, $version, null, Attributes::create([]));
    }
}
