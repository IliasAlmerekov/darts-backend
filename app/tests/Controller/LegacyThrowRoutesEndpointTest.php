<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Game;
use App\Entity\GamePlayers;
use App\Enum\GameStatus;
use App\Tests\Support\DevFunctionalKernel;
use App\Tests\Support\TestUserFactory;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * The legacy full-state throw routes stay live but deprecated until a real removal date exists.
 */
final class LegacyThrowRoutesEndpointTest extends WebTestCase
{
    private const string USER_PREFIX = 'legacythrow';

    public function testLegacyThrowAndUndoAreDeprecatedWithoutSunset(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $admin = TestUserFactory::create($entityManager, self::USER_PREFIX, 'admin', ['ROLE_ADMIN']);
        $player = TestUserFactory::create($entityManager, self::USER_PREFIX, 'player', ['ROLE_PLAYER']);

        $game = (new Game())
            ->setDate(new DateTime())
            ->setStartScore(301)
            ->setDoubleOut(false)
            ->setTripleOut(false)
            ->setStatus(GameStatus::Started);
        $entityManager->persist($game);
        $seat = (new GamePlayers())->setPlayer($player)->setPosition(1)->setScore(301)->setIsWinner(false);
        $game->addGamePlayer($seat);
        $entityManager->persist($seat);
        $entityManager->flush();
        $gameId = (int) $game->getGameId();
        $uri = sprintf('/api/game/%d/throw', $gameId);
        $successor = sprintf('</api/game/%d/throw/delta>; rel="successor-version"', $gameId);

        $client->loginUser($admin);

        $client->request(
            Request::METHOD_POST,
            $uri,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['playerId' => $player->getId(), 'value' => 20]),
        );
        self::assertResponseStatusCodeSame(Response::HTTP_OK, (string) $client->getResponse()->getContent());
        $headers = $client->getResponse()->headers;
        self::assertSame('true', $headers->get('Deprecation'));
        self::assertSame($successor, $headers->get('Link'));
        self::assertFalse($headers->has('Sunset'));

        $client->request(Request::METHOD_DELETE, $uri);
        self::assertResponseStatusCodeSame(Response::HTTP_OK, (string) $client->getResponse()->getContent());
        $headers = $client->getResponse()->headers;
        self::assertSame('true', $headers->get('Deprecation'));
        self::assertSame($successor, $headers->get('Link'));
        self::assertFalse($headers->has('Sunset'));
    }

    public function testOpenApiMarksLegacyThrowOperationsDeprecated(): void
    {
        $client = static::createClient(['environment' => 'dev', 'debug' => true]);
        $client->request(Request::METHOD_GET, '/api/doc.json');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        /** @var array{paths: array<string, array<string, array<string, mixed>>>} $document */
        $document = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $throwPath = $document['paths']['/api/game/{gameId}/throw'];
        $deltaPath = $document['paths']['/api/game/{gameId}/throw/delta'];

        self::assertTrue($throwPath['post']['deprecated'] ?? false);
        self::assertTrue($throwPath['delete']['deprecated'] ?? false);
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
