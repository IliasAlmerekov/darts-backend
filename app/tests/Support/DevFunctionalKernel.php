<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

/**
 * The application kernel with `framework.test` switched on, so WebTestCase can boot it in
 * the dev environment. The production kernel keeps `framework.test` off in dev.
 * Its cache lives apart from `var/cache/dev` and `var/cache/test`.
 */
final class DevFunctionalKernel extends BaseKernel
{
    use MicroKernelTrait {
        registerContainerConfiguration as private registerApplicationConfiguration;
    }

    #[\Override]
    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $this->registerApplicationConfiguration($loader);
        $loader->load(static function (ContainerBuilder $container): void {
            $container->loadFromExtension('framework', ['test' => true]);
        });
    }

    #[\Override]
    public function getCacheDir(): string
    {
        return $this->getProjectDir().'/var/cache/'.$this->environment.'_functional';
    }

    #[\Override]
    public function getBuildDir(): string
    {
        return $this->getCacheDir();
    }
}
