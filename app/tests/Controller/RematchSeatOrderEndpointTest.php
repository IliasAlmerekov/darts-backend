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
 * A rematch of a finished game seats the last placed participant first; an aborted game keeps its order (#37).
 */
final class RematchSeatOrderEndpointTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private User $a;
    private User $b;
    private User $c;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $admin = TestUserFactory::create($this->entityManager, 'seatorder', 'admin', ['ROLE_ADMIN']);
        $this->a = TestUserFactory::create($this->entityManager, 'seatorder', 'a', ['ROLE_PLAYER']);
        $this->b = TestUserFactory::create($this->entityManager, 'seatorder', 'b', ['ROLE_PLAYER']);
        $this->c = TestUserFactory::create($this->entityManager, 'seatorder', 'c', ['ROLE_PLAYER']);
        $this->entityManager->flush();
        $this->client->loginUser($admin);
    }

    public function testRematchOfAFinishedGameSeatsTheLastPlacedFirstAndTheWinnerLast(): void
    {
        // The stored positions are the placements A, B, C.
        $oldGameId = $this->createGame(GameStatus::Finished, [[$this->a, 1, 20], [$this->b, 2, 50], [$this->c, 3, 90]]);

        $lobbyId = $this->rematch($oldGameId);

        self::assertSame(['c', 'b', 'a'], $this->seatedUsers($lobbyId));
        self::assertSame([1, 2, 3], $this->seatPositions($lobbyId));
    }

    public function testRematchOfAFinishedGameRanksByTheStoredPlacementNotByTheScore(): void
    {
        $oldGameId = $this->createGame(GameStatus::Finished, [[$this->a, 3, 0], [$this->b, 1, 0], [$this->c, 2, 0]]);

        $lobbyId = $this->rematch($oldGameId);

        self::assertSame(['a', 'c', 'b'], $this->seatedUsers($lobbyId));
    }

    public function testRematchOfAnAbortedGameKeepsTheOldSeatOrder(): void
    {
        $oldGameId = $this->createGame(GameStatus::Aborted, [[$this->c, 1, 100], [$this->a, 2, 50], [$this->b, 3, 20]]);

        $lobbyId = $this->rematch($oldGameId);

        self::assertSame(['c', 'a', 'b'], $this->seatedUsers($lobbyId));
    }

    public function testRematchIntoAnOpenLobbyKeepsSeatedPlayersAndAppendsTheMissingLastPlacedFirst(): void
    {
        // An open lobby is older than the finished game only in data from before ADR 0003.
        $lobbyId = $this->createGame(GameStatus::Lobby, [[$this->b, 1, 301]]);
        $oldGameId = $this->createGame(GameStatus::Finished, [[$this->a, 1, 20], [$this->b, 2, 50], [$this->c, 3, 90]]);

        $rematchLobbyId = $this->rematch($oldGameId);

        self::assertSame($lobbyId, $rematchLobbyId);
        self::assertSame(['b', 'c', 'a'], $this->seatedUsers($lobbyId));
    }

    public function testRematchStartOfAFinishedGameThrowsInReversePlacementOrder(): void
    {
        $oldGameId = $this->createGame(GameStatus::Finished, [[$this->a, 1, 20], [$this->b, 2, 50], [$this->c, 3, 90]]);

        $this->client->request(Request::METHOD_POST, sprintf('/api/game/%d/rematch/start', $oldGameId), [], [], ['CONTENT_TYPE' => 'application/json'], '{}');

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED, (string) $this->client->getResponse()->getContent());
        $newGameId = (int) $this->entityManager->getConnection()->fetchOne('SELECT MAX(game_id) FROM game');
        $this->entityManager->clear();
        self::assertGreaterThan($oldGameId, $newGameId);
        self::assertSame(['c', 'b', 'a'], $this->seatedUsers($newGameId));
    }

    /**
     * @param list<array{0: User, 1: int, 2: int}> $seats user, position, score
     */
    private function createGame(GameStatus $status, array $seats): int
    {
        $game = (new Game())->setDate(new DateTime())->setStartScore(301)->setDoubleOut(false)->setTripleOut(false)->setStatus($status);
        if (GameStatus::Finished === $status) {
            $game->setFinishedAt(new DateTimeImmutable());
        }
        $this->entityManager->persist($game);
        foreach ($seats as [$user, $position, $score]) {
            $seat = (new GamePlayers())->setPlayer($user)->setPosition($position)->setScore($score);
            $game->addGamePlayer($seat);
            $this->entityManager->persist($seat);
        }
        $this->entityManager->flush();

        return (int) $game->getGameId();
    }

    private function rematch(int $oldGameId): int
    {
        $this->entityManager->clear();
        $this->client->request(Request::METHOD_POST, sprintf('/api/room/%d/rematch', $oldGameId));
        $content = (string) $this->client->getResponse()->getContent();
        self::assertResponseIsSuccessful($content);
        $body = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        $this->entityManager->clear();

        return (int) ($body['gameId'] ?? 0);
    }

    /**
     * @return list<string> labels of the seated players, in turn order
     */
    private function seatedUsers(int $gameId): array
    {
        $labels = [(int) $this->a->getId() => 'a', (int) $this->b->getId() => 'b', (int) $this->c->getId() => 'c'];
        $ids = $this->entityManager->getConnection()->fetchFirstColumn('SELECT player_id FROM game_players WHERE game_id = ? ORDER BY position, game_player_id', [$gameId]);

        return array_map(static fn (mixed $id): string => $labels[(int) $id] ?? 'unknown', $ids);
    }

    /**
     * @return list<int>
     */
    private function seatPositions(int $gameId): array
    {
        return array_map('intval', $this->entityManager->getConnection()->fetchFirstColumn('SELECT position FROM game_players WHERE game_id = ? ORDER BY position', [$gameId]));
    }
}
