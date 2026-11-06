<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Game;
use App\Entity\User;
use App\Enum\GameStatus;
use App\Exception\Game\GameRoomFullException;
use App\Tests\Support\TestSeatFactory;
use App\Tests\Support\TestUserFactory;
use DateTime;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Seats change only in the lobby: remove participant, add guest and reorder answer 409 outside it (#38).
 * Removal takes the playerId from the query, then the JSON body (#235); without either it answers 400 (#261).
 * A reorder lists at most Game::MAX_PARTICIPANTS entries (#267).
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

    public function testReorderWithOneEntryPerSeatOfAFullLobbyPassesValidation(): void
    {
        $players = $this->createPlayers(Game::MAX_PARTICIPANTS);
        $gameId = $this->createGame(GameStatus::Lobby, $players);
        $positions = array_map(
            static fn (User $player, int $index): array => ['playerId' => (int) $player->getId(), 'position' => Game::MAX_PARTICIPANTS - $index],
            $players,
            array_keys($players),
        );

        $this->request(Request::METHOD_POST, sprintf('/api/room/%d/positions', $gameId), ['positions' => $positions]);

        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame((int) $players[0]->getId(), $this->storedSeats($gameId)[Game::MAX_PARTICIPANTS - 1][0]);
    }

    public function testReorderWithMoreEntriesThanAGameSeatsIsRejectedAndKeepsThePositions(): void
    {
        $gameId = $this->createGame(GameStatus::Lobby, [$this->first, $this->second]);
        $positions = array_map(
            static fn (int $index): array => ['playerId' => $index, 'position' => $index],
            range(1, Game::MAX_PARTICIPANTS + 1),
        );

        $this->request(Request::METHOD_POST, sprintf('/api/room/%d/positions', $gameId), ['positions' => $positions]);

        $this->assertError(Response::HTTP_UNPROCESSABLE_ENTITY, 'REQUEST_VALIDATION_FAILED');
        self::assertSame([[(int) $this->first->getId(), 1], [(int) $this->second->getId(), 2]], $this->storedSeats($gameId));
    }

    public function testAddGuestToFullLobbyIsRejected(): void
    {
        $handler = new TestHandler();
        $logger = static::getContainer()->get('monolog.logger.request');
        self::assertInstanceOf(Logger::class, $logger);
        $logger->pushHandler($handler);
        $gameId = $this->createGame(GameStatus::Lobby, $this->createPlayers(Game::MAX_PARTICIPANTS));
        $usersBefore = $this->userCount();

        $this->assertConflict(Request::METHOD_POST, sprintf('/api/room/%d/guest', $gameId), 'GAME_ROOM_FULL', ['username' => 'Alex']);

        self::assertCount(Game::MAX_PARTICIPANTS, $this->storedSeats($gameId));
        self::assertSame($usersBefore, $this->userCount());
        $records = array_values(array_filter($handler->getRecords(), static fn ($record): bool => ($record->context['exception'] ?? null) instanceof GameRoomFullException));
        self::assertCount(1, $records);
        self::assertSame(Level::Warning, $records[0]->level);
    }

    public function testAdminRemovesTheBodyPlayerWhenTheQueryHasNone(): void
    {
        $gameId = $this->createGame(GameStatus::Lobby, [$this->first, $this->second]);

        $this->request(Request::METHOD_DELETE, sprintf('/api/room/%d', $gameId), ['playerId' => (int) $this->first->getId()]);

        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame([[(int) $this->second->getId(), 2]], $this->storedSeats($gameId));
    }

    public function testQueryPlayerIdWinsOverTheBodyPlayerId(): void
    {
        $gameId = $this->createGame(GameStatus::Lobby, [$this->first, $this->second]);

        $this->request(Request::METHOD_DELETE, sprintf('/api/room/%d?playerId=%d', $gameId, (int) $this->first->getId()), ['playerId' => (int) $this->second->getId()]);

        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame([[(int) $this->second->getId(), 2]], $this->storedSeats($gameId));
    }

    public function testNonPositiveBodyPlayerIdIsRejected(): void
    {
        $gameId = $this->createGame(GameStatus::Lobby, [$this->first, $this->second]);

        $this->request(Request::METHOD_DELETE, sprintf('/api/room/%d', $gameId), ['playerId' => 0]);

        $this->assertError(Response::HTTP_BAD_REQUEST, 'REQUEST_PLAYER_ID_REQUIRED');
        self::assertCount(2, $this->storedSeats($gameId));
    }

    public function testWithoutPlayerIdTheRequestIsRejected(): void
    {
        $gameId = $this->createGame(GameStatus::Lobby, [$this->first, $this->second]);

        $this->request(Request::METHOD_DELETE, sprintf('/api/room/%d', $gameId));

        $this->assertError(Response::HTTP_BAD_REQUEST, 'REQUEST_PLAYER_ID_REQUIRED');
        self::assertCount(2, $this->storedSeats($gameId));
    }

    public function testRemovingFromAMissingGameIsNotFound(): void
    {
        $this->request(Request::METHOD_DELETE, sprintf('/api/room/%d?playerId=%d', 999999, (int) $this->first->getId()));

        $this->assertError(Response::HTTP_NOT_FOUND, 'GAME_NOT_FOUND');
    }

    /**
     * @return list<User>
     */
    private function createPlayers(int $count): array
    {
        $players = array_map(
            fn (int $index): User => TestUserFactory::create($this->entityManager, 'seats', sprintf('player%d', $index), ['ROLE_PLAYER']),
            range(1, $count),
        );
        $this->entityManager->flush();

        return $players;
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
            $seat = TestSeatFactory::create($player)->setScore(301)->setPosition($index + 1)->setIsWinner($finished && 0 === $index);
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
        $this->assertError(Response::HTTP_CONFLICT, $errorCode);
    }

    private function assertError(int $status, string $errorCode): void
    {
        $content = (string) $this->client->getResponse()->getContent();
        self::assertResponseStatusCodeSame($status, $content);
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
