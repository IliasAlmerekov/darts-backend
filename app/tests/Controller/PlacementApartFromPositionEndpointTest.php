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
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Position is the turn order and only the lobby writes it. Checkout, undo, manual end and reopen
 * work on placement, so none of them reorders the turns (#29).
 */
final class PlacementApartFromPositionEndpointTest extends WebTestCase
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
        $admin = TestUserFactory::create($this->entityManager, 'placement', 'admin', ['ROLE_ADMIN']);
        $this->a = TestUserFactory::create($this->entityManager, 'placement', 'a', ['ROLE_PLAYER']);
        $this->b = TestUserFactory::create($this->entityManager, 'placement', 'b', ['ROLE_PLAYER']);
        $this->c = TestUserFactory::create($this->entityManager, 'placement', 'c', ['ROLE_PLAYER']);
        $this->entityManager->flush();
        $this->client->loginUser($admin);
    }

    public function testThrowAfterUndoFollowsTheLobbyOrderAndKeepsEveryPosition(): void
    {
        // Seat ids run a, b; the lobby order is b, a.
        $gameId = $this->createGame(301, [[$this->a, 2, 301], [$this->b, 1, 301]]);

        $this->throwDart($gameId, $this->b, 20, Response::HTTP_OK);
        $this->undo($gameId);
        $this->throwDart($gameId, $this->b, 20, Response::HTTP_OK);

        self::assertSame(['a' => 2, 'b' => 1], $this->positions($gameId));
    }

    public function testNextTurnAfterACheckoutAndAnUndoFollowsTheLobbyOrderNotTheSeatId(): void
    {
        // Seat ids run a, c, b; the lobby order is a, b, c. a checks out with the first dart.
        $gameId = $this->createGame(20, [[$this->a, 1, 20], [$this->c, 3, 20], [$this->b, 2, 20]]);
        $this->throwDart($gameId, $this->a, 20, Response::HTTP_OK);
        $this->throwDart($gameId, $this->b, 1, Response::HTTP_OK);

        $this->undo($gameId);

        $this->throwDart($gameId, $this->c, 1, Response::HTTP_CONFLICT);
        $this->throwDart($gameId, $this->b, 1, Response::HTTP_OK);
        self::assertSame(['a' => 1, 'b' => 2, 'c' => 3], $this->positions($gameId));
    }

    public function testNextTurnAfterACheckoutFollowsTheZeroBasedLobbyOrderNotTheSeatId(): void
    {
        // The lobby posts positions 0..N-1. Seat ids run b, a, c; the lobby order is a, b, c.
        // a checks out with the first dart; the next turn goes to b, the seat after a in the lobby.
        $gameId = $this->createGame(20, [[$this->b, 1, 20], [$this->a, 0, 20], [$this->c, 2, 20]]);

        $this->throwDart($gameId, $this->a, 20, Response::HTTP_OK);

        $this->throwDart($gameId, $this->c, 1, Response::HTTP_CONFLICT);
        $this->throwDart($gameId, $this->b, 1, Response::HTTP_OK);
        self::assertSame(['a' => 0, 'b' => 1, 'c' => 2], $this->positions($gameId));
    }

    public function testManualEndThenReopenKeepsTheTurnOrderAndClearsThePlacementsOfSeatsWithScoreLeft(): void
    {
        // b has the lowest score, so b takes placement 1 at the manual end.
        $gameId = $this->createGame(301, [[$this->a, 1, 200], [$this->b, 2, 100], [$this->c, 3, 250]]);

        $this->request(Request::METHOD_POST, sprintf('/api/game/%d/finish', $gameId));
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        $this->request(Request::METHOD_PATCH, sprintf('/api/game/%d/reopen', $gameId));
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        $this->throwDart($gameId, $this->b, 1, Response::HTTP_CONFLICT);
        $this->throwDart($gameId, $this->a, 1, Response::HTTP_OK);
        self::assertSame(['a' => 1, 'b' => 2, 'c' => 3], $this->positions($gameId));
        self::assertSame(['a' => null, 'b' => null, 'c' => null], $this->placements($gameId));
    }

    public function testUndoOfAThrowThatDidNotWinKeepsTheWinnerAndPlacement(): void
    {
        $gameId = $this->createGame(20, [[$this->a, 1, 20], [$this->b, 2, 20], [$this->c, 3, 20]]);
        $this->throwDart($gameId, $this->a, 20, Response::HTTP_OK);
        $this->throwDart($gameId, $this->b, 1, Response::HTTP_OK);

        $this->undo($gameId);

        self::assertSame(['a' => true, 'b' => false, 'c' => false], $this->winnerFlags($gameId));
        self::assertSame($this->a->getId(), $this->gameWinnerId($gameId));
        self::assertSame(['a' => 1, 'b' => null, 'c' => null], $this->placements($gameId));
    }

    public function testUndoOfTheWinningCheckoutClearsPlacementAndWinner(): void
    {
        $gameId = $this->createGame(20, [[$this->a, 1, 20], [$this->b, 2, 20], [$this->c, 3, 20]]);
        $this->throwDart($gameId, $this->a, 20, Response::HTTP_OK);

        $this->undo($gameId);

        self::assertSame(['a' => false, 'b' => false, 'c' => false], $this->winnerFlags($gameId));
        self::assertNull($this->gameWinnerId($gameId));
        self::assertSame(['a' => null, 'b' => null, 'c' => null], $this->placements($gameId));
    }

    public function testLastCheckoutPlacesEverySeatAndLeavesThePositionsAlone(): void
    {
        // Lobby order c, a, b; a checks out, then b checks out and c is left.
        $gameId = $this->createGame(20, [[$this->a, 2, 20], [$this->b, 3, 20], [$this->c, 1, 20]]);
        $this->throwDart($gameId, $this->c, 1, Response::HTTP_OK);
        $this->throwDart($gameId, $this->c, 1, Response::HTTP_OK);
        $this->throwDart($gameId, $this->c, 1, Response::HTTP_OK);
        $this->throwDart($gameId, $this->a, 20, Response::HTTP_OK);
        $this->throwDart($gameId, $this->b, 20, Response::HTTP_OK);

        self::assertSame(['a' => 2, 'b' => 3, 'c' => 1], $this->positions($gameId));
        self::assertSame(['a' => 1, 'b' => 2, 'c' => 3], $this->placements($gameId));
        self::assertSame($this->a->getId(), $this->gameWinnerId($gameId));
    }

    /**
     * @param list<array{0: User, 1: int, 2: int}> $seats user, position, score; created in this order
     */
    private function createGame(int $startScore, array $seats): int
    {
        $game = (new Game())->setDate(new DateTime())->setStartScore($startScore)->setOutRule(\App\Enum\OutRule::Single)->setStatus(GameStatus::Started)->setRound(1);
        $this->entityManager->persist($game);
        foreach ($seats as [$user, $position, $score]) {
            $seat = (new GamePlayers())->setPlayer($user)->setPosition($position)->setScore($score);
            $game->addGamePlayer($seat);
            $this->entityManager->persist($seat);
        }
        $this->entityManager->flush();
        $this->entityManager->clear();

        return (int) $game->getGameId();
    }

    private function throwDart(int $gameId, User $player, int $value, int $expectedStatus): void
    {
        $this->request(Request::METHOD_POST, sprintf('/api/game/%d/throw/delta', $gameId), ['playerId' => (int) $player->getId(), 'value' => $value]);
        self::assertResponseStatusCodeSame($expectedStatus, (string) $this->client->getResponse()->getContent());
    }

    private function undo(int $gameId): void
    {
        $this->request(Request::METHOD_DELETE, sprintf('/api/game/%d/throw/delta', $gameId));
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

    /**
     * @return array<string, int|null>
     */
    private function positions(int $gameId): array
    {
        return $this->seatColumn($gameId, 'position', static fn (mixed $value): ?int => null === $value ? null : (int) $value);
    }

    /**
     * @return array<string, int|null>
     */
    private function placements(int $gameId): array
    {
        return $this->seatColumn($gameId, 'placement', static fn (mixed $value): ?int => null === $value ? null : (int) $value);
    }

    /**
     * @return array<string, bool|null>
     */
    private function winnerFlags(int $gameId): array
    {
        return $this->seatColumn($gameId, 'is_winner', static fn (mixed $value): ?bool => null === $value ? null : (bool) $value);
    }

    /**
     * @param callable(mixed): (int|bool|null) $cast
     *
     * @return array<string, int|bool|null> keyed by the player label a, b or c
     */
    private function seatColumn(int $gameId, string $column, callable $cast): array
    {
        $labels = [(int) $this->a->getId() => 'a', (int) $this->b->getId() => 'b', (int) $this->c->getId() => 'c'];
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(sprintf('SELECT player_id, %s AS value FROM game_players WHERE game_id = ?', $column), [$gameId]);
        $values = [];
        foreach ($rows as $row) {
            $values[$labels[(int) $row['player_id']]] = $cast($row['value']);
        }
        ksort($values);

        return $values;
    }

    private function gameWinnerId(int $gameId): ?int
    {
        $winnerId = $this->entityManager->getConnection()->fetchOne('SELECT winner_id FROM game WHERE game_id = ?', [$gameId]);

        return false === $winnerId || null === $winnerId ? null : (int) $winnerId;
    }
}
