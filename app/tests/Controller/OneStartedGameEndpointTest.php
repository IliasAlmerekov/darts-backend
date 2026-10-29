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
        if ($withOpenLobby) {
            $this->createGame(GameStatus::Lobby, []);
        }
        $started = $this->createGame(GameStatus::Started, [$bob]);
        $finished = $this->createGame(GameStatus::Finished, [$bob]);

        $this->postJson(sprintf('/api/room/%d/rematch', $finished->getGameId()));

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame(GameStatus::Aborted, $this->statusOf($started));
        self::assertSame(GameStatus::Finished, $this->statusOf($finished));
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
    public function testRematchThenStartAbortsTheOtherStartedGame(bool $withOpenLobby): void
    {
        $bob = $this->createPlayer('bob');
        $vanya = $this->createPlayer('vanya');
        $lobby = $withOpenLobby ? $this->createGame(GameStatus::Lobby, []) : null;
        $started = $this->createGame(GameStatus::Started, [$bob]);
        $finished = $this->createGame(GameStatus::Finished, [$bob, $vanya]);

        $rematch = $this->postJson(sprintf('/api/room/%d/rematch', $finished->getGameId()));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $this->postJson(sprintf('/api/game/%d/start', $rematch['gameId']));

        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame(GameStatus::Aborted, $this->statusOf($started));
        self::assertSame(GameStatus::Finished, $this->statusOf($finished));
        // The rematch is the open lobby, or the lobby the request created and so the newest game.
        self::assertSame($lobby?->getGameId() ?? $this->newestGameId(), $rematch['gameId']);
        self::assertSame(GameStatus::Started, $this->statusOfId((int) $rematch['gameId']));
    }

    /**
     * The exit flow aborts the started game, then asks for a rematch of that same game.
     *
     * @param string $uriPattern
     */
    #[DataProvider('rematchRoutes')]
    public function testRematchOfTheNewestAbortedGameReturnsALobby(string $uriPattern): void
    {
        $aborted = $this->createGame(GameStatus::Started, [$this->createPlayer('bob'), $this->createPlayer('vanya')]);
        $this->requestJson(Request::METHOD_PATCH, sprintf('/api/game/%d/abort', $aborted->getGameId()));
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        $this->postJson(sprintf($uriPattern, $aborted->getGameId()));

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED, (string) $this->client->getResponse()->getContent());
        self::assertGreaterThan($aborted->getGameId(), $this->newestGameId());
        self::assertSame(GameStatus::Aborted, $this->statusOf($aborted));
    }

    #[DataProvider('rejectedRematchTargets')]
    public function testRematchOfAGameThatIsNotTheNewestFinishedOrAbortedGameIsRejected(string $uriPattern, GameStatus $targetStatus, ?GameStatus $newerStatus): void
    {
        $bob = $this->createPlayer('bob');
        $target = $this->createGame($targetStatus, [$bob, $this->createPlayer('vanya')]);
        $newer = null !== $newerStatus ? $this->createGame($newerStatus, [$bob]) : null;
        $gameCount = $this->gameCount();

        $this->postJson(sprintf($uriPattern, $target->getGameId()));

        $this->assertGameNotNewest();
        self::assertSame($targetStatus, $this->statusOf($target));
        if (null !== $newer) {
            self::assertSame($newerStatus, $this->statusOf($newer));
        }
        self::assertSame($gameCount, $this->gameCount());
    }

    /**
     * @return array<string, array{string, GameStatus, GameStatus|null}>
     */
    public static function rejectedRematchTargets(): array
    {
        $targets = [];
        foreach (self::rematchRoutes() as $route => [$uriPattern]) {
            $targets[$route.': older finished, newer started'] = [$uriPattern, GameStatus::Finished, GameStatus::Started];
            $targets[$route.': older finished, newer lobby'] = [$uriPattern, GameStatus::Finished, GameStatus::Lobby];
            $targets[$route.': older aborted, newer started'] = [$uriPattern, GameStatus::Aborted, GameStatus::Started];
            $targets[$route.': older aborted, newer finished'] = [$uriPattern, GameStatus::Aborted, GameStatus::Finished];
            $targets[$route.': newest started'] = [$uriPattern, GameStatus::Started, null];
        }

        return $targets;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rematchRoutes(): array
    {
        return [
            'rematch' => ['/api/room/%d/rematch'],
        ];
    }

    public function testReopenAbortsTheOtherStartedGame(): void
    {
        $bob = $this->createPlayer('bob');
        $started = $this->createGame(GameStatus::Started, [$bob]);
        $lobby = $this->createGame(GameStatus::Lobby, [$bob]);
        $finished = $this->createGame(GameStatus::Finished, [$bob]);

        $this->requestJson(Request::METHOD_PATCH, sprintf('/api/game/%d/reopen', $finished->getGameId()));

        self::assertResponseIsSuccessful();
        self::assertSame(GameStatus::Aborted, $this->statusOf($started));
        self::assertSame(GameStatus::Started, $this->statusOf($finished));
        self::assertSame(GameStatus::Lobby, $this->statusOf($lobby));
    }

    #[DataProvider('newerGameStatuses')]
    public function testReopenOfAnOlderFinishedGameIsRejectedAndKeepsTheNewerGame(GameStatus $newerStatus): void
    {
        $bob = $this->createPlayer('bob');
        $finished = $this->createGame(GameStatus::Finished, [$bob]);
        $newer = $this->createGame($newerStatus, [$bob]);

        $this->requestJson(Request::METHOD_PATCH, sprintf('/api/game/%d/reopen', $finished->getGameId()));

        $this->assertGameNotNewest();
        self::assertSame(GameStatus::Finished, $this->statusOf($finished));
        self::assertSame($newerStatus, $this->statusOf($newer));
    }

    /**
     * @return array<string, array{GameStatus}>
     */
    public static function newerGameStatuses(): array
    {
        return [
            'started' => [GameStatus::Started],
            'lobby' => [GameStatus::Lobby],
            'finished' => [GameStatus::Finished],
        ];
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

    public function testUndoingTheLastThrowOfAFinishedGameAbortsTheOtherStartedGame(): void
    {
        $started = $this->createGame(GameStatus::Started, [$this->createPlayer('vanya')]);
        $finished = $this->createGameFinishedByAThrow();
        $throwUri = sprintf('/api/game/%d/throw/delta', $finished->getGameId());

        $this->requestJson(Request::METHOD_DELETE, $throwUri);

        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame(GameStatus::Started, $this->statusOf($finished));
        self::assertSame(GameStatus::Aborted, $this->statusOf($started));
    }

    /**
     * An admin who reaches an old game's summary must not abort the game in progress (ADR 0005).
     *
     * @param string $method
     * @param string $uriPattern
     */
    #[DataProvider('undoRoutes')]
    public function testUndoOnAnOlderFinishedGameIsRejectedAndKeepsTheStartedGame(string $method, string $uriPattern): void
    {
        $finished = $this->createGameFinishedByAThrow();
        $started = $this->createGame(GameStatus::Started, [$this->createPlayer('vanya')]);

        $this->requestJson($method, sprintf($uriPattern, $finished->getGameId()));

        $this->assertGameNotNewest();
        self::assertSame(GameStatus::Finished, $this->statusOf($finished));
        self::assertSame(GameStatus::Started, $this->statusOf($started));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function undoRoutes(): array
    {
        return [
            'delta' => [Request::METHOD_DELETE, '/api/game/%d/throw/delta'],
            'deprecated' => [Request::METHOD_DELETE, '/api/game/%d/throw'],
        ];
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
        self::assertFalse($this->client->getResponse()->headers->has('X-Accel-Buffering'));

        $this->client->loginUser($player);
        $this->client->request(Request::METHOD_GET, $invitationLink);
        self::assertResponseStatusCodeSame(Response::HTTP_FOUND);
        $this->client->request(Request::METHOD_GET, '/api/login/success');
        self::assertResponseIsSuccessful();
        self::assertSame($lobbyId, $this->decodeResponse()['gameId'] ?? null);
        self::assertFalse($this->client->getResponse()->headers->has('X-Accel-Buffering'));

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
     * A two-player game with start score 20 that bob finishes with one throw of 20.
     */
    private function createGameFinishedByAThrow(): Game
    {
        $bob = $this->createPlayer('bob');
        $game = $this->createGame(GameStatus::Started, [$bob, $this->createPlayer('bobsfriend')]);
        $game->setStartScore(20)->setRound(1);
        foreach ($game->getGamePlayers() as $seat) {
            $seat->setScore(20);
        }
        $this->entityManager->flush();
        $this->postJson(sprintf('/api/game/%d/throw/delta', $game->getGameId()), ['playerId' => (int) $bob->getId(), 'value' => 20]);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame(GameStatus::Finished, $this->statusOf($game));

        return $game;
    }

    private function gameCount(): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM game');
    }

    private function newestGameId(): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('SELECT MAX(game_id) FROM game');
    }

    private function assertGameNotNewest(): void
    {
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, (string) $this->client->getResponse()->getContent());
        self::assertSame('GAME_NOT_NEWEST', $this->decodeResponse()['error'] ?? null);
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
