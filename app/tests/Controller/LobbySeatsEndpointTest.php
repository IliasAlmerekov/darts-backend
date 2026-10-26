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
 * Seats change only in the lobby: remove participant, add guest and reorder answer 409 outside it (#38).
 */
final class LobbySeatsEndpointTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private User $first;
    private User $second;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $admin = TestUserFactory::create($this->entityManager, 'seats', 'admin', ['ROLE_ADMIN']);
        $this->first = TestUserFactory::create($this->entityManager, 'seats', 'first', ['ROLE_PLAYER']);
        $this->second = TestUserFactory::create($this->entityManager, 'seats', 'second', ['ROLE_PLAYER']);
        $this->entityManager->flush();
        $this->client->loginUser($admin);
    }

    public function testRemoveParticipantFromStartedGameIsRejectedAndTheGameGoesOn(): void
    {
        $gameId = $this->createGame(GameStatus::Started, [$this->first, $this->second]);

        // The second player has not thrown yet in the current round.
        $this->assertConflict(Request::METHOD_DELETE, sprintf('/api/room/%d?playerId=%d', $gameId, (int) $this->second->getId()), 'GAME_SEATS_NOT_EDITABLE');

        self::assertSame([[(int) $this->first->getId(), 1], [(int) $this->second->getId(), 2]], $this->storedSeats($gameId));
        foreach ([$this->first, $this->first, $this->first, $this->second] as $player) {
            $this->request(Request::METHOD_POST, sprintf('/api/game/%d/throw/delta', $gameId), ['playerId' => (int) $player->getId(), 'value' => 1]);
            self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        }
    }

    public function testAddGuestToStartedGameIsRejected(): void
    {
        $gameId = $this->createGame(GameStatus::Started, [$this->first, $this->second]);
        $usersBefore = $this->userCount();

        $this->assertConflict(Request::METHOD_POST, sprintf('/api/room/%d/guest', $gameId), 'GAME_SEATS_NOT_EDITABLE', ['username' => 'Alex']);

        self::assertSame([[(int) $this->first->getId(), 1], [(int) $this->second->getId(), 2]], $this->storedSeats($gameId));
        self::assertSame($usersBefore, $this->userCount());
    }

    public function testReorderInStartedGameIsRejected(): void
    {
        $gameId = $this->createGame(GameStatus::Started, [$this->first, $this->second]);

        $this->assertConflict(Request::METHOD_POST, sprintf('/api/room/%d/positions', $gameId), 'GAME_SEATS_NOT_EDITABLE', $this->swappedPositions());

        self::assertSame([[(int) $this->first->getId(), 1], [(int) $this->second->getId(), 2]], $this->storedSeats($gameId));
    }

    public function testReorderInFinishedGameKeepsTheStoredPositions(): void
    {
        $gameId = $this->createGame(GameStatus::Finished, [$this->first, $this->second]);

        $this->assertConflict(Request::METHOD_POST, sprintf('/api/room/%d/positions', $gameId), 'GAME_SEATS_NOT_EDITABLE', $this->swappedPositions());

        self::assertSame([[(int) $this->first->getId(), 1], [(int) $this->second->getId(), 2]], $this->storedSeats($gameId));
    }

    public function testReorderWithEmptyPositionsInStartedGameIsRejected(): void
    {
        $gameId = $this->createGame(GameStatus::Started, [$this->first, $this->second]);

        $this->assertConflict(Request::METHOD_POST, sprintf('/api/room/%d/positions', $gameId), 'GAME_SEATS_NOT_EDITABLE', ['positions' => []]);
    }

    public function testReorderWithEmptyPositionsInLobbyChangesNothing(): void
    {
        $gameId = $this->createGame(GameStatus::Lobby, [$this->first, $this->second]);

        $this->request(Request::METHOD_POST, sprintf('/api/room/%d/positions', $gameId), ['positions' => []]);

        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame([[(int) $this->first->getId(), 1], [(int) $this->second->getId(), 2]], $this->storedSeats($gameId));
    }

    public function testAddGuestToFullLobbyIsRejected(): void
    {
        $players = [];
        for ($index = 1; $index <= Game::MAX_PARTICIPANTS; $index++) {
            $players[] = TestUserFactory::create($this->entityManager, 'seats', sprintf('full%d', $index), ['ROLE_PLAYER']);
        }
        $this->entityManager->flush();
        $gameId = $this->createGame(GameStatus::Lobby, $players);
        $usersBefore = $this->userCount();

        $this->assertConflict(Request::METHOD_POST, sprintf('/api/room/%d/guest', $gameId), 'GAME_ROOM_FULL', ['username' => 'Alex']);

        self::assertCount(Game::MAX_PARTICIPANTS, $this->storedSeats($gameId));
        self::assertSame($usersBefore, $this->userCount());
    }

    /**
     * @param list<User> $players
     */
    private function createGame(GameStatus $status, array $players): int
    {
        $finished = GameStatus::Finished === $status;
        $game = (new Game())->setDate(new DateTime())->setStartScore(301)->setStatus($status)->setRound(1)->setFinishedAt($finished ? new DateTimeImmutable() : null);
        $this->entityManager->persist($game);
        foreach ($players as $index => $player) {
            $seat = (new GamePlayers())->setPlayer($player)->setScore(301)->setPosition($index + 1)->setIsWinner($finished && 0 === $index);
            $game->addGamePlayer($seat);
            $this->entityManager->persist($seat);
        }
        $this->entityManager->flush();
        $gameId = (int) $game->getGameId();
        $this->entityManager->clear();

        return $gameId;
    }

    /**
     * @return array{positions: list<array{playerId: int, position: int}>}
     */
    private function swappedPositions(): array
    {
        return ['positions' => [
            ['playerId' => (int) $this->first->getId(), 'position' => 2],
            ['playerId' => (int) $this->second->getId(), 'position' => 1],
        ]];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function request(string $method, string $uri, array $payload = []): void
    {
        $this->client->request($method, $uri, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR));
        $this->entityManager->clear();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function assertConflict(string $method, string $uri, string $errorCode, array $payload = []): void
    {
        $this->request($method, $uri, $payload);

        $content = (string) $this->client->getResponse()->getContent();
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, $content);
        $body = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame($errorCode, $body['error'] ?? null);
    }

    /**
     * @return list<array{int, int}>
     */
    private function storedSeats(int $gameId): array
    {
        return array_map(
            static fn (array $row): array => [(int) $row[0], (int) $row[1]],
            $this->entityManager->getConnection()->fetchAllNumeric('SELECT player_id, position FROM game_players WHERE game_id = ? ORDER BY position', [$gameId]),
        );
    }

    private function userCount(): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM `user`');
    }
}
