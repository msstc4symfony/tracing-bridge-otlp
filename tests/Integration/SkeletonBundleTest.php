<?php

declare(strict_types=1);

namespace Msstc4Symfony\SkeletonBundle\Test\Integration;

use Msstc4Symfony\SkeletonBundle\Application\Greeting\Greet\Action;
use Msstc4Symfony\SkeletonBundle\Application\Greeting\Greet\Input;
use Msstc4Symfony\SkeletonBundle\SkeletonBundle;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class SkeletonBundleTest extends TestCase
{
    public function testExtensionAlias(): void
    {
        self::assertSame('msstc4symfony_skeleton', (new SkeletonBundle())->getContainerExtension()?->getAlias());
    }

    public function testActionGreetsWithTheDefaultGreeting(): void
    {
        $container = TestContainer::compile();

        self::assertSame('Hello', $container->getParameter(SkeletonBundle::GREETING_PARAMETER));
        self::assertSame('Hello, Ada!', $this->action($container)(new Input('Ada'))->message);
    }

    public function testActionUsesTheConfiguredGreeting(): void
    {
        self::assertSame('Hi, Ada!', $this->action(TestContainer::compile(['greeting' => 'Hi']))(new Input('Ada'))->message);
    }

    #[TestWith([''])]
    #[TestWith(['   '])]
    #[TestWith([42])]
    public function testGreetingMustBeANonEmptyString(string|int $greeting): void
    {
        $this->expectException(InvalidConfigurationException::class);

        TestContainer::compile(['greeting' => $greeting]);
    }

    private function action(ContainerBuilder $container): Action
    {
        $action = $container->get(Action::class);
        self::assertInstanceOf(Action::class, $action);

        return $action;
    }
}
