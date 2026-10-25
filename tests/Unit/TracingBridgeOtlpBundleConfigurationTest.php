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
        ], $this->process([
            'enabled' => false,
            'service_name' => 'orders',
            'endpoint' => 'http://alloy:4318',
            'protocol' => 'http/json',
            'timeout_ms' => 300,
            'max_buffered_spans' => 10,
        ]));
    }

    /**
     * @param array<string, bool|int|string|null> $config
     */
    #[TestWith([['protocol' => 'http/xml']])]
    #[TestWith([['timeout_ms' => 0]])]
    #[TestWith([['max_buffered_spans' => 0]])]
    #[TestWith([['service_name' => '']])]
    #[TestWith([['endpoint' => '']])]
    public function testRejectsInvalidValues(array $config): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process($config);
    }

    /**
     * @param array<string, bool|int|string|null> $config
     *
     * @return array<array-key, mixed>
     */
    private function process(array $config): array
    {
        $extension = (new TracingBridgeOtlpBundle())->getContainerExtension();
        self::assertInstanceOf(ConfigurationExtensionInterface::class, $extension);
        $configuration = $extension->getConfiguration([], new ContainerBuilder());
        self::assertInstanceOf(ConfigurationInterface::class, $configuration);

        return (new Processor())->processConfiguration($configuration, [$config]);
    }
}
