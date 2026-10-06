<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Tests\Support\DevFunctionalKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * The API documentation routes exist in the dev environment only.
 */
final class ApiDocExposureTest extends WebTestCase
{
    /**
     * @param string $uri
     *
     * @return void
     */
    #[DataProvider('apiDocRoutesProvider')]
    public function testApiDocRoutesAreNotExposedOutsideDev(string $uri): void
    {
        $client = static::createClient();
        $client->request('GET', $uri);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /**
     * Dev boots the functional kernel variant; every other environment boots the app kernel.
     *
     * @param array<string, mixed> $options
     *
     * @return KernelInterface
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        if ('dev' !== ($options['environment'] ?? null)) {
            return parent::createKernel($options);
        }

        return new DevFunctionalKernel('dev', (bool) ($options['debug'] ?? true));
    }

    /**
     * The real dev kernel serves the doc routes to an anonymous client.
     *
     * @param string $uri
     * @param string $contentType
     *
     * @return void
     */
    #[DataProvider('devApiDocResponsesProvider')]
    public function testApiDocRoutesArePublicInDev(string $uri, string $contentType): void
    {
        $client = static::createClient(['environment' => 'dev', 'debug' => true]);
        $client->request('GET', $uri);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertStringContainsString($contentType, (string) $client->getResponse()->headers->get('Content-Type'));
    }

    /**
     * The dev-only doc routes stay public: the catch-all ^/api rule must not lock them.
     *
     * @param string $path
     * @param bool   $isPublic
     *
     * @return void
     */
    #[DataProvider('accessRuleProvider')]
    public function testSecurityConfigKeepsApiDocPublic(string $path, bool $isPublic): void
    {
        $config = Yaml::parseFile(dirname(__DIR__, 2).'/config/packages/security.yaml');
        $rules = $config['security']['access_control'];
        self::assertIsArray($rules);

        $roles = null;
        foreach ($rules as $rule) {
            if (1 === preg_match('#'.str_replace('#', '\\#', (string) $rule['path']).'#', $path)) {
                $roles = $rule['roles'];
                break;
            }
        }

        self::assertSame($isPublic ? 'PUBLIC_ACCESS' : 'ROLE_ADMIN', $roles);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function accessRuleProvider(): iterable
    {
        yield 'Swagger UI' => ['/api/doc', true];
        yield 'OpenAPI JSON' => ['/api/doc.json', true];
        yield 'lookalike doc prefix' => ['/api/docs', false];
        yield 'doc subpath' => ['/api/doc/anything', false];
        yield 'unrelated API route' => ['/api/games', false];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function devApiDocResponsesProvider(): iterable
    {
        yield 'Swagger UI' => ['/api/doc', 'text/html'];
        yield 'OpenAPI JSON' => ['/api/doc.json', 'application/json'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function apiDocRoutesProvider(): iterable
    {
        yield 'Swagger UI' => ['/api/doc'];
        yield 'OpenAPI JSON' => ['/api/doc.json'];
    }
}
