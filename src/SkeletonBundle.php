<?php

declare(strict_types=1);

namespace Msstc4Symfony\SkeletonBundle;

use Override;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * @phpstan-type BundleConfig array{greeting: non-empty-string}
 */
final class SkeletonBundle extends AbstractBundle
{
    public const GREETING_PARAMETER = 'msstc4symfony_skeleton.greeting';

    protected string $extensionAlias = 'msstc4symfony_skeleton';

    #[Override]
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->stringNode('greeting')
                    ->cannotBeEmpty()
                    ->defaultValue('Hello')
                    ->validate()
                        ->ifTrue(static fn (string $value): bool => trim($value) === '')
                        ->thenInvalid('The greeting must not be blank.')
                    ->end()
                ->end()
            ->end()
        ;
    }

    /**
     * Reason for the ignore: Symfony declares $config as a plain array; configure() guarantees the shape.
     * The identifier is listed twice because the method has two parent declarations
     * (AbstractBundle and ConfigurableExtensionInterface), each reporting it.
     *
     * @param BundleConfig $config
     */
    #[Override] // @phpstan-ignore method.childParameterType, method.childParameterType
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->setParameter(self::GREETING_PARAMETER, $config['greeting']);

        $container->import(__DIR__ . '/Resources/config/services.php');
    }
}
