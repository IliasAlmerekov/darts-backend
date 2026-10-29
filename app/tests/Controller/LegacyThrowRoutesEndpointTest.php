<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\Support\DevFunctionalKernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;

final class LegacyThrowRoutesEndpointTest extends WebTestCase
{
    public function testOpenApiOmitsLegacyThrowOperations(): void
    {
        $client = static::createClient(['environment' => 'dev', 'debug' => true]);
        $client->request(Request::METHOD_GET, '/api/doc.json');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        /** @var array{paths: array<string, array<string, array<string, mixed>>>} $document */
        $document = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $deltaPath = $document['paths']['/api/game/{gameId}/throw/delta'];

        self::assertArrayNotHasKey('/api/game/{gameId}/throw', $document['paths']);
        self::assertArrayNotHasKey('deprecated', $deltaPath['post']);
        self::assertArrayNotHasKey('deprecated', $deltaPath['delete']);
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
}
