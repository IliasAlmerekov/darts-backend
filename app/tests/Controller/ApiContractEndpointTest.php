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
use App\Entity\User;
use App\Enum\GameStatus;
use App\Tests\Support\TestUserFactory;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Contract with the frontend: the type discriminator, 409 for unfinished summaries,
 * and 400 for non-integer pagination parameters.
 */
final class ApiContractEndpointTest extends WebTestCase
{
    private const string USER_PREFIX = 'apicontract';

    public function testFullStateResponseAndAcksCarryTypeDiscriminator(): void
    {
        $client = static::createClient();
        [$admin, $player] = $this->users();
        $gameId = $this->createGame($player, GameStatus::Started);
        $client->loginUser($admin);

        $state = $this->requestJson($client, Request::METHOD_GET, sprintf('/api/game/%d', $gameId));
        self::assertSame('full-state', $state['type'] ?? null);

        $throw = $this->requestJson(
            $client,
            Request::METHOD_POST,
            sprintf('/api/game/%d/throw/delta', $gameId),
            (string) json_encode(['playerId' => $player->getId(), 'value' => 20]),
        );
        self::assertSame('ack', $throw['type'] ?? null);

        $undo = $this->requestJson($client, Request::METHOD_DELETE, sprintf('/api/game/%d/throw/delta', $gameId));
        self::assertSame('ack', $undo['type'] ?? null);

        $legacy = $this->requestJson(
            $client,
            Request::METHOD_POST,
            sprintf('/api/game/%d/throw', $gameId),
            (string) json_encode(['playerId' => $player->getId(), 'value' => 20]),
        );
        self::assertSame('full-state', $legacy['type'] ?? null);
    }

    #[DataProvider('finishedRoutes')]
    public function testFinishedReturns409ForUnfinishedGame(string $routePattern): void
    {
        $client = static::createClient();
        [$admin, $player] = $this->users();
        $gameId = $this->createGame($player, GameStatus::Started);
        $client->loginUser($admin);

        $body = $this->requestJson($client, Request::METHOD_GET, sprintf($routePattern, $gameId), null, Response::HTTP_CONFLICT);

        self::assertSame('GAME_NOT_FINISHED', $body['error'] ?? null, (string) json_encode($body));
    }

    #[DataProvider('finishedRoutes')]
    public function testFinishedReturns200ForFinishedGame(string $routePattern): void
    {
        $client = static::createClient();
        [$admin, $player] = $this->users();
        $gameId = $this->createGame($player, GameStatus::Finished);
        $client->loginUser($admin);

        $this->requestJson($client, Request::METHOD_GET, sprintf($routePattern, $gameId));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function finishedRoutes(): array
    {
        return [
            'singular route' => ['/api/game/%d/finished'],
            'plural route' => ['/api/games/%d/finished'],
        ];
    }

    #[DataProvider('badPagination')]
    public function testStatsEndpointsReturn400ForNonIntegerPagination(string $uri): void
    {
        $client = static::createClient();
        [$admin] = $this->users();
        $client->loginUser($admin);

        $client->request(Request::METHOD_GET, $uri);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST, (string) $client->getResponse()->getContent());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badPagination(): array
    {
        return [
            'overview offset NaN' => ['/api/games/overview?offset=NaN'],
            'overview limit abc' => ['/api/games/overview?limit=abc'],
            'player stats offset NaN' => ['/api/players/stats?offset=NaN'],
            'player stats limit abc' => ['/api/players/stats?limit=abc'],
        ];
    }

    /**
     * @return array{0: User, 1: User}
     */
    private function users(): array
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $admin = TestUserFactory::create($entityManager, self::USER_PREFIX, 'admin', ['ROLE_ADMIN']);
        $player = TestUserFactory::create($entityManager, self::USER_PREFIX, 'player', ['ROLE_PLAYER']);
        $entityManager->flush();

        return [$admin, $player];
    }

    private function createGame(User $player, GameStatus $status): int
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $game = (new Game())
            ->setDate(new DateTime())
            ->setStartScore(301)
            ->setDoubleOut(false)
            ->setTripleOut(false)
            ->setStatus($status);
        $entityManager->persist($game);
        $seat = (new GamePlayers())->setPlayer($player)->setPosition(1)->setScore(301)->setIsWinner(false);
        $game->addGamePlayer($seat);
        $entityManager->persist($seat);
        $entityManager->flush();

        return (int) $game->getGameId();
    }

    /**
     * @return array<string, mixed>
     */
    private function requestJson(KernelBrowser $client, string $method, string $uri, ?string $content = null, int $expectedStatus = Response::HTTP_OK): array
    {
        $client->request($method, $uri, [], [], ['CONTENT_TYPE' => 'application/json'], $content);
        self::assertResponseStatusCodeSame($expectedStatus, (string) $client->getResponse()->getContent());

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
