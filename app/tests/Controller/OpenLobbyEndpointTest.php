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
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * At most one game is in the lobby; room create and rematch return it (ADR 0003).
 */
final class OpenLobbyEndpointTest extends WebTestCase
{
    private const string USER_PREFIX = 'openlobby';

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

    public function testRoomCreateWithOpenLobbyReturnsItAndAppliesOnlyTheSettings(): void
    {
        $bob = $this->createPlayer('bob');
        $vanya = $this->createPlayer('vanya');
        $anna = $this->createPlayer('anna');
        $previous = $this->createGame(GameStatus::Finished, [$anna]);
        $lobby = $this->createGame(GameStatus::Lobby, [$bob, $vanya]);
        $gameCount = $this->countGames();

        $response = $this->postJson('/api/room/create', [
            'previousGameId' => $previous->getGameId(),
            'playerIds' => [(int) $anna->getId()],
            'excludePlayerIds' => [(int) $bob->getId()],
            'startScore' => 501,
            'doubleOut' => true,
            'tripleOut' => false,
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame(['success' => true, 'gameId' => $lobby->getGameId()], $response);
        self::assertSame($gameCount, $this->countGames());
        $reloaded = $this->reload($lobby);
        self::assertSame(GameStatus::Lobby, $reloaded->getStatus());
        self::assertSame(501, $reloaded->getStartScore());
        self::assertTrue($reloaded->isDoubleOut());
        self::assertFalse($reloaded->isTripleOut());
        self::assertSame([$bob->getId(), $vanya->getId()], $this->seatedPlayerIds($reloaded));
    }

    public function testRoomCreateWithoutOpenLobbyCreatesOne(): void
    {
        $bob = $this->createPlayer('bob');
        $vanya = $this->createPlayer('vanya');
        $this->createGame(GameStatus::Started, [$bob]);
        $gameCount = $this->countGames();

        $response = $this->postJson('/api/room/create', [
            'playerIds' => [(int) $bob->getId(), (int) $vanya->getId()],
            'startScore' => 501,
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame($gameCount + 1, $this->countGames());
        $created = $this->entityManager->find(Game::class, $response['gameId']);
        self::assertInstanceOf(Game::class, $created);
        self::assertSame(GameStatus::Lobby, $created->getStatus());
        self::assertSame(501, $created->getStartScore());
        self::assertSame([$bob->getId(), $vanya->getId()], $this->seatedPlayerIds($created));
    }

    /**
     * A typed summary URL can point the frontend at an older game, so the server ignores
     * previousGameId and seats the requested players as they come (#133).
     */
    public function testRoomCreateIgnoresPreviousGameIdAndSeatsTheRequestedPlayers(): void
    {
        $bob = $this->createPlayer('bob');
        $vanya = $this->createPlayer('vanya');
        $anna = $this->createPlayer('anna');
        $older = $this->createGame(GameStatus::Finished, [$anna, $bob]);
        $this->createGame(GameStatus::Finished, [$vanya]);

        $response = $this->postJson('/api/room/create', [
            'previousGameId' => $older->getGameId(),
            'playerIds' => [(int) $vanya->getId(), (int) $bob->getId(), (int) $anna->getId()],
            'excludePlayerIds' => [(int) $anna->getId()],
        ]);

        self::assertResponseIsSuccessful();
        $created = $this->entityManager->find(Game::class, $response['gameId']);
        self::assertInstanceOf(Game::class, $created);
        self::assertSame([$vanya->getId(), $bob->getId()], $this->seatedPlayerIds($created));
    }

    public function testRoomCreateWithAnUnknownPlayerIsRejectedAndOpensNoLobby(): void
    {
        $bob = $this->createPlayer('bob');
        $unknownId = (int) $this->entityManager->getConnection()->fetchOne('SELECT COALESCE(MAX(id), 0) + 1000 FROM `user`');
        $gameCount = $this->countGames();

        $response = $this->postJson('/api/room/create', ['playerIds' => [(int) $bob->getId(), $unknownId]]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame('PLAYER_NOT_FOUND', $response['error'] ?? null);
        self::assertSame($gameCount, $this->countGames());
    }

    public function testRoomCreateWithOpenLobbyIgnoresMorePlayersThanAGameSeats(): void
    {
        $lobby = $this->createGame(GameStatus::Lobby, []);

        $response = $this->postJson('/api/room/create', ['playerIds' => $this->createPlayerIds(Game::MAX_PARTICIPANTS + 1)]);

        self::assertResponseIsSuccessful();
        self::assertSame(['success' => true, 'gameId' => $lobby->getGameId()], $response);
        self::assertSame([], $this->seatedPlayerIds($this->reload($lobby)));
    }

    public function testRoomCreateWithMorePlayersThanAGameSeatsIsRejectedAndOpensNoLobby(): void
    {
        $gameCount = $this->countGames();

        $response = $this->postJson('/api/room/create', ['playerIds' => $this->createPlayerIds(Game::MAX_PARTICIPANTS + 1)]);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('GAME_ROOM_FULL', $response['error'] ?? null);
        self::assertSame($gameCount, $this->countGames());
    }

    public function testRoomCreateCountsTheSeatLimitAfterExcludingPlayers(): void
    {
        $playerIds = $this->createPlayerIds(Game::MAX_PARTICIPANTS + 1);

        $response = $this->postJson('/api/room/create', ['playerIds' => $playerIds, 'excludePlayerIds' => [$playerIds[0]]]);

        self::assertResponseIsSuccessful();
        $created = $this->entityManager->find(Game::class, $response['gameId']);
        self::assertInstanceOf(Game::class, $created);
        self::assertSame(array_slice($playerIds, 1), $this->seatedPlayerIds($created));
    }

    public function testRematchWithOpenLobbyReturnsItWithMissingPlayersAndOldSettings(): void
    {
        $bob = $this->createPlayer('bob');
        $vanya = $this->createPlayer('vanya');
        $anna = $this->createPlayer('anna');
        $lobby = $this->createGame(GameStatus::Lobby, [$vanya, $anna]);
        $finished = $this->createGame(GameStatus::Finished, [$bob, $vanya]);
        $finished->setStartScore(501)->setOutRule(\App\Enum\OutRule::Double);
        $this->entityManager->flush();
        $gameCount = $this->countGames();

        $response = $this->postJson(sprintf('/api/room/%d/rematch', $finished->getGameId()));

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertTrue($response['success']);
        self::assertSame($lobby->getGameId(), $response['gameId']);
        self::assertSame($gameCount, $this->countGames());
        $reloaded = $this->reload($lobby);
        self::assertSame(GameStatus::Lobby, $reloaded->getStatus());
        self::assertSame([$vanya->getId(), $anna->getId(), $bob->getId()], $this->seatedPlayerIds($reloaded));
        self::assertSame(501, $reloaded->getStartScore());
        self::assertTrue($reloaded->isDoubleOut());
        self::assertFalse($reloaded->isTripleOut());
    }

    /**
     * The first rematch makes the lobby the newest game, so the second one targets an older
     * game (ADR 0005).
     */
    public function testRepeatedRematchIsRejectedAndKeepsTheLobbySeats(): void
    {
        $bob = $this->createPlayer('bob');
        $vanya = $this->createPlayer('vanya');
        $anna = $this->createPlayer('anna');
        $finished = $this->createGame(GameStatus::Finished, [$bob, $vanya]);

        $first = $this->postJson(sprintf('/api/room/%d/rematch', $finished->getGameId()));
        $lobby = $this->entityManager->find(Game::class, $first['gameId']);
        self::assertInstanceOf(Game::class, $lobby);
        $seat = (new GamePlayers())->setPlayer($this->entityManager->find(User::class, $anna->getId()))->setPosition(3);
        $lobby->addGamePlayer($seat);
        $this->entityManager->persist($seat);
        $this->entityManager->flush();
        $second = $this->postJson(sprintf('/api/room/%d/rematch', $finished->getGameId()));

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('GAME_NOT_NEWEST', $second['error'] ?? null);
        self::assertSame([$vanya->getId(), $bob->getId(), $anna->getId()], $this->seatedPlayerIds($this->reload($lobby)));
    }

    public function testRematchWithoutOpenLobbyCreatesOne(): void
    {
        $bob = $this->createPlayer('bob');
        $vanya = $this->createPlayer('vanya');
        $finished = $this->createGame(GameStatus::Finished, [$bob, $vanya]);
        $finished->setStartScore(501)->setOutRule(\App\Enum\OutRule::Double);
        $this->entityManager->flush();
        $gameCount = $this->countGames();

        $response = $this->postJson(sprintf('/api/room/%d/rematch', $finished->getGameId()));

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame($gameCount + 1, $this->countGames());
        $created = $this->entityManager->find(Game::class, $response['gameId']);
        self::assertInstanceOf(Game::class, $created);
        self::assertSame(GameStatus::Lobby, $created->getStatus());
        self::assertSame(501, $created->getStartScore());
        self::assertTrue($created->isDoubleOut());
        // Bob placed first, so he takes the last seat.
        self::assertSame([$vanya->getId(), $bob->getId()], $this->seatedPlayerIds($created));
    }

    public function testRematchThenStartWithOpenLobbyStartsThatLobbyWithEveryPlayer(): void
    {
        $bob = $this->createPlayer('bob');
        $vanya = $this->createPlayer('vanya');
        $anna = $this->createPlayer('anna');
        $lobby = $this->createGame(GameStatus::Lobby, [$bob, $anna]);
        $finished = $this->createGame(GameStatus::Finished, [$bob, $vanya]);
        $finished->setStartScore(501)->setOutRule(\App\Enum\OutRule::Double);
        $this->entityManager->flush();
        $gameCount = $this->countGames();

        $this->postJson(sprintf('/api/room/%d/rematch', $finished->getGameId()));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $this->postJson(sprintf('/api/game/%d/start', $lobby->getGameId()));

        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame($gameCount, $this->countGames());
        $reloaded = $this->reload($lobby);
        self::assertSame(GameStatus::Started, $reloaded->getStatus());
        self::assertSame([$bob->getId(), $anna->getId(), $vanya->getId()], $this->seatedPlayerIds($reloaded));
        self::assertSame(501, $reloaded->getStartScore());
        self::assertTrue($reloaded->isDoubleOut());
    }

    public function testRematchStopsAddingPlayersWhenTheLobbyIsFull(): void
    {
        $lobbyPlayers = array_map(fn (int $index): User => $this->createPlayer('lobby'.$index), range(1, 9));
        $bob = $this->createPlayer('bob');
        $vanya = $this->createPlayer('vanya');
        $lobby = $this->createGame(GameStatus::Lobby, $lobbyPlayers);
        $finished = $this->createGame(GameStatus::Finished, [$bob, $vanya]);

        $this->postJson(sprintf('/api/room/%d/rematch', $finished->getGameId()));

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame(
            [...array_map(static fn (User $player): ?int => $player->getId(), $lobbyPlayers), $vanya->getId()],
            $this->seatedPlayerIds($this->reload($lobby)),
        );
    }

    /**
     * @return list<int>
     */
    private function createPlayerIds(int $count): array
    {
        return array_map(fn (int $index): int => (int) $this->createPlayer('seat'.$index)->getId(), range(1, $count));
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
            $seat = (new GamePlayers())->setPlayer($player)->setPosition($index + 1);
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
        $this->client->request(Request::METHOD_POST, $uri, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR));
        $this->entityManager->clear();

        /** @var array<string, mixed> $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        return $data;
    }

    private function countGames(): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM game');
    }

    private function reload(Game $game): Game
    {
        $reloaded = $this->entityManager->find(Game::class, $game->getGameId());
        self::assertInstanceOf(Game::class, $reloaded);

        return $reloaded;
    }

    /**
     * @return list<int|null>
     */
    private function seatedPlayerIds(Game $game): array
    {
        $seats = $game->getGamePlayers()->toArray();
        usort($seats, static fn (GamePlayers $a, GamePlayers $b): int => $a->getPosition() <=> $b->getPosition());

        return array_values(array_map(static fn (GamePlayers $seat): ?int => $seat->getPlayer()?->getId(), $seats));
    }
}
