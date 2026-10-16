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
 * GET /api/player/current-game: the newest Lobby or Started game the player still sits in.
 */
final class PlayerCurrentGameEndpointTest extends WebTestCase
{
    private const string USER_PREFIX = 'currentgame';
    private const string URI = '/api/player/current-game';

    public function testPlayerInLobbyGetsGameIdAndLobbyStatus(): void
    {
        $client = static::createClient();
        $player = $this->createUser('player', 'ROLE_PLAYER');
        $gameId = $this->createGame(GameStatus::Lobby, $player);
        $client->loginUser($player);

        self::assertSame(['gameId' => $gameId, 'status' => 'lobby'], $this->getCurrentGame($client));
    }

    public function testPlayerInStartedGameGetsStartedStatus(): void
    {
        $client = static::createClient();
        $player = $this->createUser('player', 'ROLE_PLAYER');
        $gameId = $this->createGame(GameStatus::Started, $player);
        $client->loginUser($player);

        self::assertSame(['gameId' => $gameId, 'status' => 'started'], $this->getCurrentGame($client));
    }

    public function testPlayerInNoGameGetsNulls(): void
    {
        $client = static::createClient();
        $player = $this->createUser('player', 'ROLE_PLAYER');
        $this->createGame(GameStatus::Lobby, $this->createUser('other', 'ROLE_PLAYER'));
        $client->loginUser($player);

        self::assertSame(['gameId' => null, 'status' => null], $this->getCurrentGame($client));
    }

    public function testPlayerRemovedFromRoomGetsNulls(): void
    {
        $client = static::createClient();
        $admin = $this->createUser('admin', 'ROLE_ADMIN');
        $player = $this->createUser('player', 'ROLE_PLAYER');
        $gameId = $this->createGame(GameStatus::Lobby, $player);

        $client->loginUser($admin);
        $client->request(Request::METHOD_DELETE, sprintf('/api/room/%d?playerId=%d', $gameId, $player->getId()));
        self::assertResponseIsSuccessful((string) $client->getResponse()->getContent());

        $client->loginUser($player);

        self::assertSame(['gameId' => null, 'status' => null], $this->getCurrentGame($client));
    }

    #[DataProvider('finishedStatuses')]
    public function testPlayerWithOnlyFinishedOrAbortedGamesGetsNulls(GameStatus $status): void
    {
        $client = static::createClient();
        $player = $this->createUser('player', 'ROLE_PLAYER');
        $this->createGame($status, $player);
        $client->loginUser($player);

        self::assertSame(['gameId' => null, 'status' => null], $this->getCurrentGame($client));
    }

    /**
     * @return array<string, array{GameStatus}>
     */
    public static function finishedStatuses(): array
    {
        return [
            'finished' => [GameStatus::Finished],
            'aborted' => [GameStatus::Aborted],
        ];
    }

    public function testNewestLobbyBeatsOldFinishedGame(): void
    {
        $client = static::createClient();
        $player = $this->createUser('player', 'ROLE_PLAYER');
        $this->createGame(GameStatus::Finished, $player);
        $lobbyId = $this->createGame(GameStatus::Lobby, $player);
        $client->loginUser($player);

        self::assertSame(['gameId' => $lobbyId, 'status' => 'lobby'], $this->getCurrentGame($client));
    }

    public function testMostRecentlyJoinedGameWinsOverOlderLobby(): void
    {
        $client = static::createClient();
        $player = $this->createUser('player', 'ROLE_PLAYER');
        $this->createGame(GameStatus::Lobby, $player);
        $rematchId = $this->createGame(GameStatus::Lobby, $player);
        $client->loginUser($player);

        self::assertSame(['gameId' => $rematchId, 'status' => 'lobby'], $this->getCurrentGame($client));
    }

    public function testJoinOrderWinsOverGameCreationOrder(): void
    {
        $client = static::createClient();
        $player = $this->createUser('player', 'ROLE_PLAYER');
        $olderGameId = $this->createEmptyGame(GameStatus::Lobby);
        $newerGameId = $this->createEmptyGame(GameStatus::Lobby);
        $this->seatPlayer($newerGameId, $player);
        $this->seatPlayer($olderGameId, $player);
        $client->loginUser($player);

        self::assertSame(['gameId' => $olderGameId, 'status' => 'lobby'], $this->getCurrentGame($client));
    }

    public function testStartedGameJoinedAfterLobbyWinsAndLobbyAfterStartedWins(): void
    {
        $client = static::createClient();
        $player = $this->createUser('player', 'ROLE_PLAYER');
        $this->createGame(GameStatus::Lobby, $player);
        $startedId = $this->createGame(GameStatus::Started, $player);
        $client->loginUser($player);

        self::assertSame(['gameId' => $startedId, 'status' => 'started'], $this->getCurrentGame($client));

        $lobbyId = $this->createGame(GameStatus::Lobby, $player);

        self::assertSame(['gameId' => $lobbyId, 'status' => 'lobby'], $this->getCurrentGame($client));
    }

    public function testPlayerCopiedIntoRematchLobbyGetsTheRematch(): void
    {
        $client = static::createClient();
        $admin = $this->createUser('admin', 'ROLE_ADMIN');
        $player = $this->createUser('player', 'ROLE_PLAYER');
        $finishedId = $this->createGame(GameStatus::Finished, $player);

        $client->loginUser($admin);
        $client->request(Request::METHOD_POST, sprintf('/api/room/%d/rematch', $finishedId));
        self::assertResponseIsSuccessful((string) $client->getResponse()->getContent());

        $client->loginUser($player);
        $body = $this->getCurrentGame($client);

        self::assertSame('lobby', $body['status']);
        self::assertIsInt($body['gameId']);
        self::assertNotSame($finishedId, $body['gameId']);
    }

    public function testAnonymousGets401(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_GET, self::URI);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testAdminGets403(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('admin', 'ROLE_ADMIN'));

        $client->request(Request::METHOD_GET, self::URI);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    /**
     * @return array<string, mixed>
     */
    private function getCurrentGame(KernelBrowser $client): array
    {
        $client->request(Request::METHOD_GET, self::URI);
        self::assertResponseStatusCodeSame(Response::HTTP_OK, (string) $client->getResponse()->getContent());

        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame(['gameId', 'status'], array_keys($body));

        return $body;
    }

    private function createUser(string $label, string $role): User
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $user = TestUserFactory::create($entityManager, self::USER_PREFIX, $label, [$role]);
        $entityManager->flush();

        return $user;
    }

    private function createGame(GameStatus $status, User $player): int
    {
        $gameId = $this->createEmptyGame($status);
        $this->seatPlayer($gameId, $player);

        return $gameId;
    }

    private function createEmptyGame(GameStatus $status): int
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $game = (new Game())
            ->setDate(new DateTime())
            ->setStartScore(301)
            ->setDoubleOut(false)
            ->setTripleOut(false)
            ->setStatus($status);
        $entityManager->persist($game);
        $entityManager->flush();

        $gameId = $game->getGameId();
        self::assertNotNull($gameId);

        return $gameId;
    }

    private function seatPlayer(int $gameId, User $player): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $game = $entityManager->find(Game::class, $gameId);
        self::assertNotNull($game);

        $seat = (new GamePlayers())
            ->setGame($game)
            ->setPlayer($player)
            ->setPosition($game->getGamePlayers()->count() + 1)
            ->setScore(301)
            ->setIsWinner(false);
        $game->addGamePlayer($seat);
        $entityManager->persist($seat);
        $entityManager->flush();
    }
}
