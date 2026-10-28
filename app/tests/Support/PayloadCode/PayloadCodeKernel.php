<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Support\PayloadCode;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * The application kernel plus one route whose payload has a constraint with a
 * `payload: ['code' => ...]` override, which no production DTO has yet, and one route
 * next to the public /api/ready that only the firewall rule can deny.
 * Its cache lives apart from the application's.
 */
final class PayloadCodeKernel extends BaseKernel
{
    public const string PATH = '/api/test/payload-code';
    public const string NEXT_TO_READY_PATH = '/api/ready/extra';

    use MicroKernelTrait {
        configureRoutes as private configureApplicationRoutes;
        registerContainerConfiguration as private registerApplicationConfiguration;
    }

    #[\Override]
    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $this->registerApplicationConfiguration($loader);
        $loader->load(static function (ContainerBuilder $container): void {
            $container->register(PayloadCodeController::class)
                ->setAutoconfigured(true)
                ->setAutowired(true)
                ->addTag('controller.service_arguments');
            $container->register(NextToReadyController::class)
                ->setAutoconfigured(true)
                ->setAutowired(true)
                ->addTag('controller.service_arguments');
        });
    }

    #[\Override]
    public function getCacheDir(): string
    {
        return $this->getProjectDir().'/var/cache/'.$this->environment.'_payload_code';
    }

    #[\Override]
    public function getBuildDir(): string
    {
        return $this->getCacheDir();
    }

    private function configureRoutes(RoutingConfigurator $routes): void
    {
        $this->configureApplicationRoutes($routes);
        $routes->add('test_payload_code', self::PATH)
            ->controller(PayloadCodeController::class)
            ->methods(['POST'])
            ->format('json');
        $routes->add('test_next_to_ready', self::NEXT_TO_READY_PATH)
            ->controller(NextToReadyController::class)
            ->methods(['GET'])
            ->format('json');
    }
}
