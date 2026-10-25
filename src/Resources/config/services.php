<?php

declare(strict_types=1);

use Msstc4Symfony\SkeletonBundle\Application\Greeting\Greet\Action;
use Msstc4Symfony\SkeletonBundle\SkeletonBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();
    $services->defaults()->autowire()->autoconfigure();

    $services->set(Action::class)
        ->arg('$greeting', param(SkeletonBundle::GREETING_PARAMETER))
    ;
};
