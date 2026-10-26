<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeOtlp\Test\Unit;

use Msstc4Symfony\TracingBridgeOtlp\TracingBridgeOtlpBundle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ConfigurationExtensionInterface;

#[CoversClass(TracingBridgeOtlpBundle::class)]
final class TracingBridgeOtlpBundleConfigurationTest extends TestCase
{
    public function testDefaults(): void
    {
        self::assertSame([
            'enabled' => true,
            'service_name' => null,
            'endpoint' => null,
            'protocol' => null,
            'timeout_ms' => 1000,
            'max_buffered_spans' => 2048,
            'pause_pool' => null,
        ], $this->process([]));
    }

    public function testAcceptsEveryOption(): void
    {
        self::assertSame([
            'enabled' => false,
            'service_name' => 'orders',
            'endpoint' => 'http://alloy:4318',
            'protocol' => 'http/json',
            'timeout_ms' => 300,
            'max_buffered_spans' => 10,
            'pause_pool' => 'cache.otlp_pause',
        ], $this->process([
            'enabled' => false,
            'service_name' => 'orders',
            'endpoint' => 'http://alloy:4318',
            'protocol' => 'http/json',
            'timeout_ms' => 300,
            'max_buffered_spans' => 10,
            'pause_pool' => 'cache.otlp_pause',
        ]));
    }

    public function testAcceptsTheLowestLimitsAndProtobuf(): void
    {
        $config = $this->process(['protocol' => 'http/protobuf', 'timeout_ms' => 1, 'max_buffered_spans' => 1]);

        self::assertSame(['http/protobuf', 1, 1], [$config['protocol'] ?? null, $config['timeout_ms'] ?? null, $config['max_buffered_spans'] ?? null]);
    }

    public function testAnExplicitTildeKeepsTheDefault(): void
    {
        $config = $this->process(['service_name' => null, 'endpoint' => null, 'pause_pool' => null]);

        self::assertSame(['service_name' => null, 'endpoint' => null, 'pause_pool' => null], array_intersect_key($config, ['service_name' => 0, 'endpoint' => 0, 'pause_pool' => 0]));
    }

    public function testALaterTildeResetsAnEarlierValue(): void
    {
        $config = $this->process(
            ['service_name' => 'orders', 'endpoint' => 'http://alloy:4318', 'pause_pool' => 'cache.otlp_pause'],
            ['service_name' => null, 'endpoint' => null, 'pause_pool' => null],
        );

        self::assertSame(['service_name' => null, 'endpoint' => null, 'pause_pool' => null], array_intersect_key($config, ['service_name' => 0, 'endpoint' => 0, 'pause_pool' => 0]));
    }

    public function testAnEmptyStringIsUnset(): void
    {
        $config = $this->process(['service_name' => '', 'endpoint' => '', 'pause_pool' => '']);

        self::assertSame(['service_name' => null, 'endpoint' => null, 'pause_pool' => null], array_intersect_key($config, ['service_name' => 0, 'endpoint' => 0, 'pause_pool' => 0]));
    }

    /**
     * @param array<string, bool|int|string|null> $config
     */
    #[TestWith([['protocol' => 'http/xml']])]
    #[TestWith([['timeout_ms' => 0]])]
    #[TestWith([['max_buffered_spans' => 0]])]
    #[TestWith([['pause_pool' => '@otlp_pause']])]
    #[TestWith([['pause_pool' => 1]])]
    #[TestWith([['service_name' => 1]])]
    #[TestWith([['endpoint' => true]])]
    #[TestWith([['service_name' => false]])]
    #[TestWith([['pause_pool' => 1.5]])]
    public function testRejectsInvalidValues(array $config): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process($config);
    }

    /**
     * @param array<string, bool|int|string|null> $config
     * @param array<string, bool|int|string|null> ...$more later configuration files
     *
     * @return array<array-key, mixed>
     */
    private function process(array $config, array ...$more): array
    {
        $extension = (new TracingBridgeOtlpBundle())->getContainerExtension();
        self::assertInstanceOf(ConfigurationExtensionInterface::class, $extension);
        $configuration = $extension->getConfiguration([], new ContainerBuilder());
        self::assertInstanceOf(ConfigurationInterface::class, $configuration);

        return (new Processor())->processConfiguration($configuration, [$config, ...$more]);
    }
}
