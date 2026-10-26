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
 * Finish, abort and undo run only from the statuses the transition table allows (#37).
 */
final class GameStatusTransitionsEndpointTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private User $first;
    private User $second;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $admin = TestUserFactory::create($this->entityManager, 'transitions', 'admin', ['ROLE_ADMIN']);
        $this->first = TestUserFactory::create($this->entityManager, 'transitions', 'first', ['ROLE_PLAYER']);
        $this->second = TestUserFactory::create($this->entityManager, 'transitions', 'second', ['ROLE_PLAYER']);
        $this->entityManager->flush();
        $this->client->loginUser($admin);
    }

    public function testFinishFromLobbyIsRejected(): void
    {
        $gameId = $this->createGame(GameStatus::Lobby);

        $this->assertConflict(Request::METHOD_POST, sprintf('/api/game/%d/finish', $gameId), 'GAME_FINISH_NOT_ALLOWED');

        self::assertSame('lobby', $this->storedStatus($gameId));
    }

    public function testFinishFromAbortedIsRejected(): void
    {
        $gameId = $this->createGame(GameStatus::Aborted);

        $this->assertConflict(Request::METHOD_POST, sprintf('/api/game/%d/finish', $gameId), 'GAME_FINISH_NOT_ALLOWED');

        self::assertSame('aborted', $this->storedStatus($gameId));
    }

    public function testFinishFromStartedFinishesTheGame(): void
    {
        $gameId = $this->createGame(GameStatus::Started);

        $this->request(Request::METHOD_POST, sprintf('/api/game/%d/finish', $gameId));

        self::assertResponseStatusCodeSame(Response::HTTP_OK, (string) $this->client->getResponse()->getContent());
        self::assertSame('finished', $this->storedStatus($gameId));
    }

    public function testFinishFromFinishedKeepsFinishedAtAndPlacements(): void
    {
        // The placements contradict the scores, so a second finish would reorder them.
        $gameId = $this->createGame(GameStatus::Finished, new DateTimeImmutable('2020-01-01 10:00:00'));
        $before = $this->storedFinishState($gameId);

        $this->request(Request::METHOD_POST, sprintf('/api/game/%d/finish', $gameId));

        self::assertResponseStatusCodeSame(Response::HTTP_OK, (string) $this->client->getResponse()->getContent());
        self::assertSame($before, $this->storedFinishState($gameId));
        self::assertSame('2020-01-01 10:00:00', $before['finishedAt']);
    }

    public function testAbortFromLobbyIsRejected(): void
    {
        $gameId = $this->createGame(GameStatus::Lobby);

        $this->assertConflict(Request::METHOD_PATCH, sprintf('/api/game/%d/abort', $gameId), 'GAME_ABORT_NOT_ALLOWED');

        self::assertSame('lobby', $this->storedStatus($gameId));
    }

    public function testAbortFromFinishedIsRejected(): void
    {
        $gameId = $this->createGame(GameStatus::Finished, new DateTimeImmutable());

        $this->assertConflict(Request::METHOD_PATCH, sprintf('/api/game/%d/abort', $gameId), 'GAME_ABORT_NOT_ALLOWED');

        self::assertSame('finished', $this->storedStatus($gameId));
    }

    public function testAbortFromStartedAbortsTheGame(): void
    {
        $gameId = $this->createGame(GameStatus::Started);

        $this->request(Request::METHOD_PATCH, sprintf('/api/game/%d/abort', $gameId));

        self::assertResponseStatusCodeSame(Response::HTTP_OK, (string) $this->client->getResponse()->getContent());
        self::assertSame('aborted', $this->storedStatus($gameId));
    }

    public function testAbortFromAbortedIsIdempotent(): void
    {
        $gameId = $this->createGame(GameStatus::Aborted);

        $this->request(Request::METHOD_PATCH, sprintf('/api/game/%d/abort', $gameId));

        self::assertResponseStatusCodeSame(Response::HTTP_OK, (string) $this->client->getResponse()->getContent());
        self::assertSame('aborted', $this->storedStatus($gameId));
    }

    public function testUndoFromLobbyIsRejected(): void
    {
        $gameId = $this->createGame(GameStatus::Lobby);

        $this->assertConflict(Request::METHOD_DELETE, sprintf('/api/game/%d/throw/delta', $gameId), 'GAME_THROW_NOT_ALLOWED');

        self::assertSame('lobby', $this->storedStatus($gameId));
    }

    public function testUndoFromAbortedIsRejectedAndDeletesNothing(): void
    {
        $gameId = $this->createGame(GameStatus::Started);
        $this->throwDart($gameId, $this->first);
        $this->request(Request::METHOD_PATCH, sprintf('/api/game/%d/abort', $gameId));
        $throwsBefore = $this->throwCount($gameId);
        self::assertSame(1, $throwsBefore);

        $this->assertConflict(Request::METHOD_DELETE, sprintf('/api/game/%d/throw/delta', $gameId), 'GAME_THROW_NOT_ALLOWED');

        self::assertSame($throwsBefore, $this->throwCount($gameId));
        self::assertSame('aborted', $this->storedStatus($gameId));
    }

    public function testUndoFromStartedDeletesTheLastThrow(): void
    {
        $gameId = $this->createGame(GameStatus::Started);
        $this->throwDart($gameId, $this->first);

        $this->request(Request::METHOD_DELETE, sprintf('/api/game/%d/throw/delta', $gameId));

        self::assertResponseStatusCodeSame(Response::HTTP_OK, (string) $this->client->getResponse()->getContent());
        self::assertSame(0, $this->throwCount($gameId));
        self::assertSame('started', $this->storedStatus($gameId));
    }

    public function testUndoFromFinishedNewestGameStartsItAgain(): void
    {
        $gameId = $this->createGame(GameStatus::Started);
        $this->throwDart($gameId, $this->first);
        $this->request(Request::METHOD_POST, sprintf('/api/game/%d/finish', $gameId));
        self::assertSame('finished', $this->storedStatus($gameId));

        $this->request(Request::METHOD_DELETE, sprintf('/api/game/%d/throw/delta', $gameId));

        self::assertResponseStatusCodeSame(Response::HTTP_OK, (string) $this->client->getResponse()->getContent());
        self::assertSame('started', $this->storedStatus($gameId));
        self::assertSame(0, $this->throwCount($gameId));
    }

    private function createGame(GameStatus $status, ?DateTimeImmutable $finishedAt = null): int
    {
        $game = (new Game())->setDate(new DateTime())->setStartScore(301)->setStatus($status)->setRound(1)->setFinishedAt($finishedAt);
        $this->entityManager->persist($game);
        // The first player leads on score; a finished game ranks them second to show the stored placements win.
        $seats = [[$this->first, 20, GameStatus::Finished === $status ? 2 : 1], [$this->second, 50, GameStatus::Finished === $status ? 1 : 2]];
        foreach ($seats as [$user, $score, $position]) {
            $seat = (new GamePlayers())->setPlayer($user)->setScore($score)->setPosition($position);
            $game->addGamePlayer($seat);
            $this->entityManager->persist($seat);
        }
        $this->entityManager->flush();
        $gameId = (int) $game->getGameId();
        $this->entityManager->clear();

        return $gameId;
    }

    private function throwDart(int $gameId, User $player): void
    {
        $this->request(Request::METHOD_POST, sprintf('/api/game/%d/throw/delta', $gameId), ['playerId' => (int) $player->getId(), 'value' => 1]);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function request(string $method, string $uri, array $payload = []): void
    {
        $this->client->request($method, $uri, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR));
        $this->entityManager->clear();
    }

    private function assertConflict(string $method, string $uri, string $errorCode): void
    {
        $this->request($method, $uri);

        $content = (string) $this->client->getResponse()->getContent();
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, $content);
        $body = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame($errorCode, $body['error'] ?? null);
    }

    private function storedStatus(int $gameId): string
    {
        return (string) $this->entityManager->getConnection()->fetchOne('SELECT status FROM game WHERE game_id = ?', [$gameId]);
    }

    /**
     * @return array{finishedAt: string|null, placements: list<mixed>}
     */
    private function storedFinishState(int $gameId): array
    {
        $connection = $this->entityManager->getConnection();
        $finishedAt = $connection->fetchOne('SELECT finished_at FROM game WHERE game_id = ?', [$gameId]);

        return [
            'finishedAt' => false === $finishedAt || null === $finishedAt ? null : (string) $finishedAt,
            'placements' => $connection->fetchFirstColumn('SELECT position FROM game_players WHERE game_id = ? ORDER BY player_id', [$gameId]),
        ];
    }

    private function throwCount(int $gameId): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM round_throws rt JOIN `round` r ON r.round_id = rt.round_id WHERE r.game_id = ?',
            [$gameId],
        );
    }
}
