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
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * At most one game is started: opening a lobby or starting a game aborts the other started
 * games (ADR 0004).
 */
final class OneStartedGameEndpointTest extends WebTestCase
{
    private const string USER_PREFIX = 'onestarted';

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private User $admin;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->admin = TestUserFactory::create($this->entityManager, self::USER_PREFIX, 'admin', ['ROLE_ADMIN']);
        $this->entityManager->flush();
        $this->client->loginUser($this->admin);
    }

    public function testRoomCreateThatCreatesALobbyAbortsTheStartedGame(): void
    {
        $bob = $this->createPlayer('bob');
        $started = $this->createGame(GameStatus::Started, [$bob]);

        $this->postJson('/api/room/create', ['playerIds' => [(int) $bob->getId()]]);

        self::assertResponseIsSuccessful();
        self::assertSame(GameStatus::Aborted, $this->statusOf($started));
    }

    public function testRoomCreateThatReturnsTheOpenLobbyAbortsTheStartedGameAndLeavesTheRest(): void
    {
        $bob = $this->createPlayer('bob');
        $started = $this->createGame(GameStatus::Started, [$bob]);
        $finished = $this->createGame(GameStatus::Finished, [$bob]);
        $lobby = $this->createGame(GameStatus::Lobby, [$bob]);

        $response = $this->postJson('/api/room/create');

        self::assertResponseIsSuccessful();
        self::assertSame($lobby->getGameId(), $response['gameId']);
        self::assertSame(GameStatus::Aborted, $this->statusOf($started));
        self::assertSame(GameStatus::Finished, $this->statusOf($finished));
        self::assertSame(GameStatus::Lobby, $this->statusOf($lobby));
    }

    #[DataProvider('lobbyReuse')]
    public function testRematchAbortsTheStartedGame(bool $withOpenLobby): void
    {
        $bob = $this->createPlayer('bob');
        $started = $this->createGame(GameStatus::Started, [$bob]);
        $finished = $this->createGame(GameStatus::Finished, [$bob]);
        if ($withOpenLobby) {
            $this->createGame(GameStatus::Lobby, []);
        }

        $this->postJson(sprintf('/api/room/%d/rematch', $finished->getGameId()));

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame(GameStatus::Aborted, $this->statusOf($started));
        self::assertSame(GameStatus::Finished, $this->statusOf($finished));
    }

    #[DataProvider('lobbyReuse')]
    public function testDeprecatedSettingsCreateAbortsTheStartedGame(bool $withOpenLobby): void
    {
        $started = $this->createGame(GameStatus::Started, [$this->createPlayer('bob')]);
        if ($withOpenLobby) {
            $this->createGame(GameStatus::Lobby, []);
        }

        $this->postJson('/api/game/settings', ['startScore' => 501]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame(GameStatus::Aborted, $this->statusOf($started));
    }

    public function testStartAbortsTheOtherStartedGame(): void
    {
        $bob = $this->createPlayer('bob');
        $vanya = $this->createPlayer('vanya');
        $started = $this->createGame(GameStatus::Started, [$bob]);
        $finished = $this->createGame(GameStatus::Finished, [$bob]);
        $lobby = $this->createGame(GameStatus::Lobby, [$bob, $vanya]);

        $this->postJson(sprintf('/api/game/%d/start', $lobby->getGameId()));

        self::assertResponseIsSuccessful();
        self::assertSame(GameStatus::Aborted, $this->statusOf($started));
        self::assertSame(GameStatus::Finished, $this->statusOf($finished));
        self::assertSame(GameStatus::Started, $this->statusOf($lobby));
    }

    #[DataProvider('lobbyReuse')]
    public function testRematchStartAbortsTheOtherStartedGame(bool $withOpenLobby): void
    {
        $bob = $this->createPlayer('bob');
        $vanya = $this->createPlayer('vanya');
        $started = $this->createGame(GameStatus::Started, [$bob]);
        $finished = $this->createGame(GameStatus::Finished, [$bob, $vanya]);
        if ($withOpenLobby) {
            $this->createGame(GameStatus::Lobby, []);
        }

        $this->postJson(sprintf('/api/game/%d/rematch/start', $finished->getGameId()));

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame(GameStatus::Aborted, $this->statusOf($started));
        self::assertSame(GameStatus::Finished, $this->statusOf($finished));
        // The rematch is the newest game: either the open lobby or the lobby the request created.
        $rematchId = (int) $this->entityManager->getConnection()->fetchOne('SELECT MAX(game_id) FROM game');
        self::assertSame(GameStatus::Started, $this->statusOfId($rematchId));
    }

    public function testReopenAbortsTheOtherStartedGame(): void
    {
        $bob = $this->createPlayer('bob');
        $started = $this->createGame(GameStatus::Started, [$bob]);
        $finished = $this->createGame(GameStatus::Finished, [$bob]);
        $lobby = $this->createGame(GameStatus::Lobby, [$bob]);

        $this->requestJson(Request::METHOD_PATCH, sprintf('/api/game/%d/reopen', $finished->getGameId()));

        self::assertResponseIsSuccessful();
        self::assertSame(GameStatus::Aborted, $this->statusOf($started));
        self::assertSame(GameStatus::Started, $this->statusOf($finished));
        self::assertSame(GameStatus::Lobby, $this->statusOf($lobby));
    }

    public function testReopeningAStartedGameChangesNoOtherGame(): void
    {
        $bob = $this->createPlayer('bob');
        $other = $this->createGame(GameStatus::Started, [$bob]);
        $reopened = $this->createGame(GameStatus::Started, [$bob]);

        $this->requestJson(Request::METHOD_PATCH, sprintf('/api/game/%d/reopen', $reopened->getGameId()));

        self::assertResponseIsSuccessful();
        self::assertSame(GameStatus::Started, $this->statusOf($other));
        self::assertSame(GameStatus::Started, $this->statusOf($reopened));
    }

    /**
     * The tablet closed during game A. P joins the next lobby B and the admin removes P from B.
     */
    public function testPlayerRemovedFromTheNextLobbyHasNoCurrentGame(): void
    {
        $player = $this->createPlayer('player');
        $abandoned = $this->createGame(GameStatus::Started, [$player, $this->createPlayer('other')]);

        $lobbyId = $this->postJson('/api/room/create')['gameId'];
        self::assertIsInt($lobbyId);
        $invitationLink = $this->postJson(sprintf('/api/invite/create/%d', $lobbyId))['invitationLink'];
        self::assertIsString($invitationLink);

        $this->client->loginUser($player);
        $this->client->request(Request::METHOD_GET, $invitationLink);
        self::assertResponseStatusCodeSame(Response::HTTP_FOUND);
        $this->postJson('/api/invite/process');
        self::assertResponseIsSuccessful();

        $this->client->loginUser($this->admin);
        $this->client->request(Request::METHOD_DELETE, sprintf('/api/room/%d?playerId=%d', $lobbyId, $player->getId()));
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        $this->client->loginUser($player);
        $this->client->request(Request::METHOD_GET, '/api/player/current-game');
        self::assertResponseIsSuccessful();
        self::assertSame(['gameId' => null, 'status' => null], $this->decodeResponse());

        $this->client->request(Request::METHOD_GET, '/api/login/success');
        self::assertResponseIsSuccessful();
        $login = $this->decodeResponse();
        self::assertIsString($login['redirect']);
        self::assertSame('/playerprofile', parse_url($login['redirect'], PHP_URL_PATH));
        self::assertArrayNotHasKey('gameId', $login);
        self::assertSame(GameStatus::Aborted, $this->statusOf($abandoned));
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function lobbyReuse(): array
    {
        return [
            'new lobby' => [false],
            'open lobby' => [true],
        ];
    }

    private function createPlayer(string $label): User
    {
        $player = TestUserFactory::create($this->entityManager, self::USER_PREFIX, $label, ['ROLE_PLAYER']);
        $this->entityManager->flush();

        return $player;
    }

    /**
     * @param list<User> $players
     */
    private function createGame(GameStatus $status, array $players): Game
    {
        $game = (new Game())
            ->setDate(new DateTime())
            ->setStatus($status);
        if (GameStatus::Finished === $status) {
            $game->setFinishedAt(new DateTimeImmutable());
        }
        $this->entityManager->persist($game);
        foreach ($players as $index => $player) {
            $seat = (new GamePlayers())->setPlayer($player)->setPosition($index + 1)->setScore(301);
            $game->addGamePlayer($seat);
            $this->entityManager->persist($seat);
        }
        $this->entityManager->flush();

        return $game;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function postJson(string $uri, array $payload = []): array
    {
        return $this->requestJson(Request::METHOD_POST, $uri, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function requestJson(string $method, string $uri, array $payload = []): array
    {
        $this->client->request($method, $uri, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR));
        $this->entityManager->clear();

        return $this->decodeResponse();
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        return $data;
    }

    private function statusOf(Game $game): GameStatus
    {
        return $this->statusOfId((int) $game->getGameId());
    }

    private function statusOfId(int $gameId): GameStatus
    {
        $reloaded = $this->entityManager->find(Game::class, $gameId);
        self::assertInstanceOf(Game::class, $reloaded);

        return $reloaded->getStatus();
    }
}
