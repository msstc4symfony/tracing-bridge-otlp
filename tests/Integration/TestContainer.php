<?php

declare(strict_types=1);

namespace Msstc4Symfony\SkeletonBundle\Test\Integration;

use LogicException;
use Msstc4Symfony\SkeletonBundle\SkeletonBundle;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

final class TestContainer
{
    /**
     * @param array{greeting?: string|int} $config msstc4symfony_skeleton configuration
     */
    public static function compile(array $config = []): ContainerBuilder
    {
        $container = new ContainerBuilder(new ParameterBag([
            'kernel.environment' => 'test',
            'kernel.debug' => false,
            // Symfony 7.4's PhpFileLoader reads it; 8.x does not.
            'kernel.build_dir' => sys_get_temp_dir(),
        ]));
        $bundle = new SkeletonBundle();
        $extension = $bundle->getContainerExtension() ?? throw new LogicException('SkeletonBundle has no extension.');
        $container->registerExtension($extension);
        $container->loadFromExtension($extension->getAlias(), $config);

        $bundle->build($container);

        // The bundle's own services are private; keep them inspectable after compilation.
        $container->addCompilerPass(new MakeBundleServicesPublicPass(), PassConfig::TYPE_BEFORE_REMOVING);
        $container->compile(true);

        return $container;
    }
}
