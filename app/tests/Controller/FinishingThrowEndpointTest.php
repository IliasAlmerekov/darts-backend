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
use App\Enum\OutRule;
use App\Tests\Support\TestUserFactory;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The throw that finishes a game closes its round, opens no new one, and leaves no active player (#59).
 */
final class FinishingThrowEndpointTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private User $first;
    private User $second;
    private int $gameId;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $admin = TestUserFactory::create($this->entityManager, 'finishthrow', 'admin', ['ROLE_ADMIN']);
        $this->first = TestUserFactory::create($this->entityManager, 'finishthrow', 'first', ['ROLE_PLAYER']);
        $this->second = TestUserFactory::create($this->entityManager, 'finishthrow', 'second', ['ROLE_PLAYER']);
        $this->entityManager->flush();
        $this->client->loginUser($admin);
    }

    public function testLastSeatFinishClosesTheRoundWithoutOpeningANewOne(): void
    {
        $this->createGame(firstScore: 100, secondScore: 1);
        $this->throwDarts($this->first, 3);

        $ack = $this->throwDart($this->second);

        self::assertSame('finished', $this->storedGame()['status']);
        self::assertSame([1 => true], $this->roundsFinished());
        self::assertSame(1, $this->storedGame()['round']);
        $this->assertNoActivePlayerInAck($ack);
        $this->assertNoActivePlayerInState();
    }

    public function testFirstSeatFinishClosesTheRoundAndLeavesNoActivePlayer(): void
    {
        $this->createGame(firstScore: 1, secondScore: 100);

        $ack = $this->throwDart($this->first);

        self::assertSame('finished', $this->storedGame()['status']);
        self::assertSame([1 => true], $this->roundsFinished());
        self::assertSame(1, $this->storedGame()['round']);
        $this->assertNoActivePlayerInAck($ack);
        $this->assertNoActivePlayerInState();
    }

    public function testUndoOfALastSeatFinishReopensTheRoundForTheFinisher(): void
    {
        $this->createGame(firstScore: 100, secondScore: 1);
        $this->throwDarts($this->first, 3);
        $this->throwDart($this->second);

        $this->request(Request::METHOD_DELETE, sprintf('/api/game/%d/throw/delta', $this->gameId));
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        $this->assertReopenedForTheFinisher((int) $this->second->getId());
    }

    public function testUndoOfAFirstSeatFinishReopensTheRoundForTheFinisher(): void
    {
        $this->createGame(firstScore: 1, secondScore: 100);
        $this->throwDart($this->first);

        $this->request(Request::METHOD_DELETE, sprintf('/api/game/%d/throw/delta', $this->gameId));
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        $this->assertReopenedForTheFinisher((int) $this->first->getId());
    }

    public function testReopenAfterALastSeatFinishLetsTheRemainingSeatThrow(): void
    {
        $this->createGame(firstScore: 100, secondScore: 1);
        $this->throwDarts($this->first, 3);
        $this->throwDart($this->second);

        $this->request(Request::METHOD_PATCH, sprintf('/api/game/%d/reopen', $this->gameId));
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        $state = $this->state();
        self::assertSame('started', $state['status'] ?? null);
        self::assertSame((int) $this->first->getId(), $state['activePlayerId'] ?? null);
        self::assertSame(2, $state['currentRound'] ?? null);

        $this->throwDart($this->first);

        self::assertSame([1 => true, 2 => false], $this->roundsFinished());
        self::assertSame(2, $this->storedGame()['round']);
    }

    public function testReopenAfterAFirstSeatFinishReopensTheRoundForTheNextSeat(): void
    {
        $this->createGame(firstScore: 1, secondScore: 100);
        $this->throwDart($this->first);

        $this->request(Request::METHOD_PATCH, sprintf('/api/game/%d/reopen', $this->gameId));
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame([1 => false], $this->roundsFinished());

        $this->throwDart($this->second);

        self::assertSame([1 => false], $this->roundsFinished());
        self::assertSame(['status' => 'started', 'round' => 1], $this->storedGame());
    }

    public function testReopenOfALegacyLastSeatFinishPlaysOnInTheEmptyNextRound(): void
    {
        $this->createLegacyLastSeatFinish();

        $this->request(Request::METHOD_PATCH, sprintf('/api/game/%d/reopen', $this->gameId));
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame((int) $this->first->getId(), $this->state()['activePlayerId'] ?? null);

        $this->throwDart($this->first);

        self::assertSame([1 => true, 2 => false], $this->roundsFinished());
        self::assertSame(['status' => 'started', 'round' => 2], $this->storedGame());
    }

    public function testUndoOfALegacyLastSeatFinishRemovesTheEmptyNextRound(): void
    {
        $this->createLegacyLastSeatFinish();

        $this->request(Request::METHOD_DELETE, sprintf('/api/game/%d/throw/delta', $this->gameId));
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        $this->assertReopenedForTheFinisher((int) $this->second->getId());
    }

    /**
     * A last-seat finish as the code before #59 stored it: an empty next round and the round counter on it.
     */
    private function createLegacyLastSeatFinish(): void
    {
        $this->createGame(firstScore: 100, secondScore: 1);
        $this->throwDarts($this->first, 3);
        $this->throwDart($this->second);
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('INSERT INTO `round` (game_id, round_number, started_at) VALUES (?, 2, NOW())', [$this->gameId]);
        $connection->executeStatement('UPDATE game SET round = 2 WHERE game_id = ?', [$this->gameId]);
        self::assertSame([1 => true, 2 => false], $this->roundsFinished());
        self::assertSame(['status' => 'finished', 'round' => 2], $this->storedGame());
    }

    private function assertReopenedForTheFinisher(int $finisherId): void
    {
        self::assertSame(['status' => 'started', 'round' => 1], $this->storedGame());
        self::assertSame([1 => false], $this->roundsFinished());
        $state = $this->state();
        self::assertSame($finisherId, $state['activePlayerId'] ?? null);
        self::assertSame(1, $state['currentRound'] ?? null);
    }

    /**
     * @param array<string, mixed> $ack
     */
    private function assertNoActivePlayerInAck(array $ack): void
    {
        $delta = $ack['scoreboardDelta'] ?? null;
        self::assertIsArray($delta);
        self::assertSame('finished', $delta['status'] ?? null);
        $players = $delta['changedPlayers'] ?? null;
        self::assertIsArray($players);
        self::assertCount(2, $players);
        self::assertSame([false, false], array_column($players, 'isActive'));
    }

    private function assertNoActivePlayerInState(): void
    {
        $state = $this->state();
        self::assertSame('finished', $state['status'] ?? null);
        self::assertArrayHasKey('activePlayerId', $state);
        self::assertNull($state['activePlayerId']);
        $players = $state['players'] ?? null;
        self::assertIsArray($players);
        self::assertSame([false, false], array_column($players, 'isActive'));
    }

    private function createGame(int $firstScore, int $secondScore): void
    {
        $game = (new Game())->setDate(new DateTime())->setStartScore(301)->setOutRule(OutRule::Single)->setStatus(GameStatus::Started)->setRound(1);
        $this->entityManager->persist($game);
        foreach ([[$this->first, $firstScore, 1], [$this->second, $secondScore, 2]] as [$user, $score, $position]) {
            $seat = (new GamePlayers())->setPlayer($user)->setScore($score)->setPosition($position)->setIsWinner(false);
            $game->addGamePlayer($seat);
            $this->entityManager->persist($seat);
        }
        $this->entityManager->flush();
        $this->gameId = (int) $game->getGameId();
        $this->entityManager->clear();
    }

    private function throwDarts(User $player, int $count): void
    {
        for ($i = 0; $i < $count; ++$i) {
            $this->throwDart($player);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function throwDart(User $player): array
    {
        $this->request(Request::METHOD_POST, sprintf('/api/game/%d/throw/delta', $this->gameId), ['playerId' => (int) $player->getId(), 'value' => 1]);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        return $this->decodeResponse();
    }

    /**
     * @return array<string, mixed>
     */
    private function state(): array
    {
        $this->request(Request::METHOD_GET, sprintf('/api/game/%d', $this->gameId));
        self::assertResponseStatusCodeSame(Response::HTTP_OK, (string) $this->client->getResponse()->getContent());

        return $this->decodeResponse();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function request(string $method, string $uri, array $payload = []): void
    {
        $body = [] === $payload ? null : json_encode($payload, JSON_THROW_ON_ERROR);
        $this->client->request($method, $uri, [], [], ['CONTENT_TYPE' => 'application/json'], $body);
        $this->entityManager->clear();
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(): array
    {
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }

    /**
     * @return array{status: string, round: int}
     */
    private function storedGame(): array
    {
        $row = $this->entityManager->getConnection()->fetchAssociative('SELECT status, round FROM game WHERE game_id = ?', [$this->gameId]);
        self::assertIsArray($row);

        return ['status' => (string) $row['status'], 'round' => (int) $row['round']];
    }

    /**
     * Whether each round of the game is finished, keyed by round number; a duplicate number fails.
     *
     * @return array<int, bool>
     */
    private function roundsFinished(): array
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT round_number, finished_at FROM `round` WHERE game_id = ? ORDER BY round_id',
            [$this->gameId],
        );
        $finished = [];
        foreach ($rows as $row) {
            $roundNumber = (int) $row['round_number'];
            self::assertArrayNotHasKey($roundNumber, $finished, sprintf('Round %d exists twice.', $roundNumber));
            $finished[$roundNumber] = null !== $row['finished_at'];
        }

        return $finished;
    }
}
