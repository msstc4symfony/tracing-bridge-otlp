<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Test\Unit\Infrastructure\Otlp;

use Msstc4Symfony\TracingBridgeOtlp\Infrastructure\Otlp\ResourceFactory;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResourceFactory::class)]
final class ResourceFactoryTest extends TestCase
{
    private const VARIABLES = ['OTEL_SERVICE_NAME', 'OTEL_RESOURCE_ATTRIBUTES'];

    #[Override]
    protected function tearDown(): void
    {
        foreach (self::VARIABLES as $name) {
            putenv($name);
        }
    }

    public function testTheConfiguredNameWins(): void
    {
        putenv('OTEL_SERVICE_NAME=from-env');

        self::assertSame('orders', ResourceFactory::resource('orders', '/srv/app')->getAttributes()->get('service.name'));
    }

    public function testTheEnvironmentNamesTheServiceWhenNotConfigured(): void
    {
        putenv('OTEL_SERVICE_NAME=from-env');

        self::assertSame('from-env', ResourceFactory::resource(null, '/srv/app')->getAttributes()->get('service.name'));
    }

    public function testResourceAttributesNameTheServiceWhenNotConfigured(): void
    {
        putenv('OTEL_RESOURCE_ATTRIBUTES=service.name=from-attributes,deployment.environment.name=prod');

        $attributes = ResourceFactory::resource(null, '/srv/app')->getAttributes();

        self::assertSame('from-attributes', $attributes->get('service.name'));
        self::assertSame('prod', $attributes->get('deployment.environment.name'));
    }

    public function testTheProjectDirectoryNamesTheServiceOtherwise(): void
    {
        self::assertSame('billing', ResourceFactory::resource(null, '/srv/billing')->getAttributes()->get('service.name'));
    }

    public function testAnEmptyNameIsUnset(): void
    {
        self::assertSame('billing', ResourceFactory::resource('', '/srv/billing')->getAttributes()->get('service.name'));
    }

    public function testTheScopeIsThisPackage(): void
    {
        $scope = ResourceFactory::scope();

        self::assertSame('msstc4symfony/tracing-bridge-otlp', $scope->getName());
        self::assertNotNull($scope->getVersion());
    }
}
