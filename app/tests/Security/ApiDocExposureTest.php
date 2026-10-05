<?php

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

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
     * @return iterable<string, array{string}>
     */
    public static function apiDocRoutesProvider(): iterable
    {
        yield 'Swagger UI' => ['/api/doc'];
        yield 'OpenAPI JSON' => ['/api/doc.json'];
    }
}
