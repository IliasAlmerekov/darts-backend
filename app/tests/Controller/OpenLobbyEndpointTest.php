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
            'tripleOut' => true,
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame(['success' => true, 'gameId' => $lobby->getGameId()], $response);
        self::assertSame($gameCount, $this->countGames());
        $reloaded = $this->reload($lobby);
        self::assertSame(GameStatus::Lobby, $reloaded->getStatus());
        self::assertSame(501, $reloaded->getStartScore());
        self::assertTrue($reloaded->isDoubleOut());
        self::assertTrue($reloaded->isTripleOut());
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

    public function testRematchWithOpenLobbyReturnsItWithMissingPlayersAndOldSettings(): void
    {
        $bob = $this->createPlayer('bob');
        $vanya = $this->createPlayer('vanya');
        $anna = $this->createPlayer('anna');
        $finished = $this->createGame(GameStatus::Finished, [$bob, $vanya]);
        $finished->setStartScore(501)->setDoubleOut(true)->setTripleOut(true)->setType(2);
        $lobby = $this->createGame(GameStatus::Lobby, [$vanya, $anna]);
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
        self::assertTrue($reloaded->isTripleOut());
        self::assertSame(2, $reloaded->getType());
    }

    public function testRematchOverwritesTheLobbyTypeEvenWhenTheOldGameHasNone(): void
    {
        $bob = $this->createPlayer('bob');
        $finished = $this->createGame(GameStatus::Finished, [$bob]);
        $lobby = $this->createGame(GameStatus::Lobby, []);
        $lobby->setType(2);
        $this->entityManager->flush();

        $this->postJson(sprintf('/api/room/%d/rematch', $finished->getGameId()));

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertNull($this->reload($lobby)->getType());
    }

    public function testRepeatedRematchReturnsTheSameLobbyWithoutDuplicateSeats(): void
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

        self::assertSame($first['gameId'], $second['gameId']);
        self::assertSame([$bob->getId(), $vanya->getId(), $anna->getId()], $this->seatedPlayerIds($this->reload($lobby)));
    }

    public function testRematchWithoutOpenLobbyCreatesOne(): void
    {
        $bob = $this->createPlayer('bob');
        $vanya = $this->createPlayer('vanya');
        $finished = $this->createGame(GameStatus::Finished, [$bob, $vanya]);
        $finished->setStartScore(501)->setDoubleOut(true);
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
        self::assertSame([$bob->getId(), $vanya->getId()], $this->seatedPlayerIds($created));
    }

    public function testRematchStartWithOpenLobbyStartsThatLobbyWithEveryPlayer(): void
    {
        $bob = $this->createPlayer('bob');
        $vanya = $this->createPlayer('vanya');
        $anna = $this->createPlayer('anna');
        $finished = $this->createGame(GameStatus::Finished, [$bob, $vanya]);
        $finished->setStartScore(501)->setDoubleOut(true);
        $lobby = $this->createGame(GameStatus::Lobby, [$bob, $vanya, $anna]);
        $gameCount = $this->countGames();

        $this->postJson(sprintf('/api/game/%d/rematch/start', $finished->getGameId()));

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame($gameCount, $this->countGames());
        $reloaded = $this->reload($lobby);
        self::assertSame(GameStatus::Started, $reloaded->getStatus());
        self::assertSame([$bob->getId(), $vanya->getId(), $anna->getId()], $this->seatedPlayerIds($reloaded));
        self::assertSame(501, $reloaded->getStartScore());
        self::assertTrue($reloaded->isDoubleOut());
    }

    public function testRematchStartWithoutOpenLobbyCreatesAndStartsOne(): void
    {
        $bob = $this->createPlayer('bob');
        $vanya = $this->createPlayer('vanya');
        $finished = $this->createGame(GameStatus::Finished, [$bob, $vanya]);
        $gameCount = $this->countGames();

        $this->postJson(sprintf('/api/game/%d/rematch/start', $finished->getGameId()));

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame($gameCount + 1, $this->countGames());
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
