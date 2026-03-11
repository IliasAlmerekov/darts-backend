<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Game;
use App\Entity\Round;
use App\Entity\RoundThrows;
use App\Entity\User;
use App\Enum\GameStatus;
use App\Repository\RoundThrowsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RoundThrowsRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private RoundThrowsRepository $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repo = static::getContainer()->get(RoundThrowsRepository::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->em->close();
    }

    public function testFindLatestForGame(): void
    {
        $game = $this->createGame(GameStatus::Started);
        $round = $this->createRound($game, 1);
        $user = $this->createUser('p1');

        $older = $this->createThrow($game, $round, $user, throwNumber: 1, value: 10, score: 10);
        $latest = $this->createThrow($game, $round, $user, throwNumber: 2, value: 20, score: 30);
        $this->em->flush();

        $result = $this->repo->findLatestForGame($game->getGameId());

        self::assertNotNull($result);
        self::assertSame($latest->getThrowId(), $result['id']);
        self::assertSame($latest->getThrowNumber(), $result['throwNumber']);
    }

    public function testFindEntityLatestForGameAndPlayer(): void
    {
        $game = $this->createGame(GameStatus::Started);
        $round = $this->createRound($game, 1);
        $playerA = $this->createUser('playerA');
        $playerB = $this->createUser('playerB');

        $this->createThrow($game, $round, $playerA, throwNumber: 1, value: 5, score: 5);
        $latestA = $this->createThrow($game, $round, $playerA, throwNumber: 2, value: 15, score: 20);
        $latestB = $this->createThrow($game, $round, $playerB, throwNumber: 1, value: 25, score: 25);
        $this->em->flush();

        $foundForGame = $this->repo->findEntityLatestForGame($game->getGameId());
        $foundForPlayer = $this->repo->findLatestForGameAndPlayer($game->getGameId(), $playerA->getId());

        self::assertSame($latestB->getThrowId(), $foundForGame?->getThrowId());
        self::assertSame($latestA->getThrowId(), $foundForPlayer?->getThrowId());
    }

    public function testFindByGameIdOrderedReturnsThrowsAcrossWholeGameInStableOrder(): void
    {
        $game = $this->createGame(GameStatus::Started);
        $round1 = $this->createRound($game, 1, finished: true);
        $round2 = $this->createRound($game, 2);

        $playerA = $this->createUser('orderedA');
        $playerB = $this->createUser('orderedB');

        $throwA1 = $this->createThrow($game, $round1, $playerA, 1, 10, 10);
        $throwB1 = $this->createThrow($game, $round1, $playerB, 1, 20, 20);
        $throwA2 = $this->createThrow($game, $round2, $playerA, 1, 30, 40);
        $throwB2 = $this->createThrow($game, $round2, $playerB, 2, 40, 60);
        $this->em->flush();

        $orderedThrows = $this->repo->findByGameIdOrdered($game->getGameId());

        self::assertSame([
            $throwA1->getThrowId(),
            $throwB1->getThrowId(),
            $throwA2->getThrowId(),
            $throwB2->getThrowId(),
        ], array_map(static fn(RoundThrows $throw): ?int => $throw->getThrowId(), $orderedThrows));
    }

    public function testFindCurrentRoundThrowsForGamePlayersReturnsScalarRows(): void
    {
        $game = $this->createGame(GameStatus::Started);
        $round1 = $this->createRound($game, 1, finished: true);
        $round2 = $this->createRound($game, 2);

        $playerA = $this->createUser('current-a');
        $playerB = $this->createUser('current-b');

        $this->createThrow($game, $round1, $playerA, 1, 10, 10);
        $this->createThrow($game, $round2, $playerA, 1, 20, 30);
        $this->createThrow($game, $round2, $playerA, 2, 40, 70, isBust: true);
        $this->createThrow($game, $round2, $playerB, 1, 50, 50);
        $this->em->flush();

        $rows = $this->repo->findCurrentRoundThrowsForGamePlayers($game->getGameId(), 2);

        self::assertSame([
            [
                'playerId' => $playerA->getId(),
                'roundNumber' => 2,
                'throwNumber' => 1,
                'value' => 20,
                'isDouble' => false,
                'isTriple' => false,
                'isBust' => false,
            ],
            [
                'playerId' => $playerA->getId(),
                'roundNumber' => 2,
                'throwNumber' => 2,
                'value' => 40,
                'isDouble' => false,
                'isTriple' => false,
                'isBust' => true,
            ],
            [
                'playerId' => $playerB->getId(),
                'roundNumber' => 2,
                'throwNumber' => 1,
                'value' => 50,
                'isDouble' => false,
                'isTriple' => false,
                'isBust' => false,
            ],
        ], $rows);
    }

    public function testFindCurrentRoundStateSnapshotReturnsAggregatedPerPlayerState(): void
    {
        $game = $this->createGame(GameStatus::Started);
        $round1 = $this->createRound($game, 1, finished: true);
        $round2 = $this->createRound($game, 2);

        $playerA = $this->createUser('snapshot-a');
        $playerB = $this->createUser('snapshot-b');

        $this->createThrow($game, $round1, $playerA, 1, 10, 10);
        $this->createThrow($game, $round2, $playerA, 1, 20, 30);
        $this->createThrow($game, $round2, $playerA, 2, 40, 70, isBust: true);
        $this->createThrow($game, $round2, $playerB, 1, 50, 50);
        $this->em->flush();

        $snapshot = $this->repo->findCurrentRoundStateSnapshot($game->getGameId(), 2);

        self::assertSame([
            $playerA->getId() => [
                'throwsCount' => 2,
                'lastThrowNumber' => 2,
                'lastThrowValue' => 40,
                'lastThrowBust' => true,
            ],
            $playerB->getId() => [
                'throwsCount' => 1,
                'lastThrowNumber' => 1,
                'lastThrowValue' => 50,
                'lastThrowBust' => false,
            ],
        ], $snapshot);
    }

    public function testNormalizeRoundStateSnapshotRowsAcceptsLowercaseAndSnakeCaseKeys(): void
    {
        $method = new \ReflectionMethod($this->repo, 'normalizeRoundStateSnapshotRows');
        $method->setAccessible(true);

        /** @var array<int, array{throwsCount:int,lastThrowNumber:int|null,lastThrowValue:int|null,lastThrowBust:bool}> $snapshot */
        $snapshot = $method->invoke($this->repo, [
            [
                'playerid' => '31',
                'throwscount' => '3',
                'lastthrownumber' => '3',
                'lastthrowvalue' => '25',
                'lastthrowbust' => false,
            ],
            [
                'player_id' => '32',
                'throws_count' => '0',
                'last_throw_number' => null,
                'last_throw_value' => null,
                'last_throw_bust' => false,
            ],
        ]);

        self::assertSame([
            31 => [
                'throwsCount' => 3,
                'lastThrowNumber' => 3,
                'lastThrowValue' => 25,
                'lastThrowBust' => false,
            ],
            32 => [
                'throwsCount' => 0,
                'lastThrowNumber' => null,
                'lastThrowValue' => null,
                'lastThrowBust' => false,
            ],
        ], $snapshot);
    }

    public function testFindLatestThrowsForGamePlayersReturnsOneRowPerPlayer(): void
    {
        $game = $this->createGame(GameStatus::Started);
        $round1 = $this->createRound($game, 1, finished: true);
        $round2 = $this->createRound($game, 2);

        $playerA = $this->createUser('latest-a');
        $playerB = $this->createUser('latest-b');

        $this->createThrow($game, $round1, $playerA, 1, 10, 10);
        $this->createThrow($game, $round2, $playerA, 1, 20, 30, isBust: true);
        $this->createThrow($game, $round1, $playerB, 1, 15, 15);
        $this->createThrow($game, $round2, $playerB, 2, 25, 40);
        $this->em->flush();

        $rows = $this->repo->findLatestThrowsForGamePlayers($game->getGameId());

        self::assertSame([
            [
                'playerId' => $playerA->getId(),
                'roundNumber' => 2,
                'throwNumber' => 1,
                'value' => 20,
                'isDouble' => false,
                'isTriple' => false,
                'isBust' => true,
            ],
            [
                'playerId' => $playerB->getId(),
                'roundNumber' => 2,
                'throwNumber' => 2,
                'value' => 25,
                'isDouble' => false,
                'isTriple' => false,
                'isBust' => false,
            ],
        ], $rows);
    }

    public function testFindRoundHistoryForGameReturnsAllThrowsInGroupingOrder(): void
    {
        $game = $this->createGame(GameStatus::Started);
        $round1 = $this->createRound($game, 1, finished: true);
        $round2 = $this->createRound($game, 2);

        $playerA = $this->createUser('history-a');
        $playerB = $this->createUser('history-b');

        $this->createThrow($game, $round1, $playerA, 1, 10, 10);
        $this->createThrow($game, $round1, $playerB, 1, 20, 20);
        $this->createThrow($game, $round2, $playerA, 1, 30, 40);
        $this->createThrow($game, $round2, $playerB, 2, 40, 60);
        $this->em->flush();

        $rows = $this->repo->findRoundHistoryForGame($game->getGameId());

        self::assertSame([
            [
                'playerId' => $playerA->getId(),
                'roundNumber' => 1,
                'throwNumber' => 1,
                'value' => 10,
                'isDouble' => false,
                'isTriple' => false,
                'isBust' => false,
            ],
            [
                'playerId' => $playerB->getId(),
                'roundNumber' => 1,
                'throwNumber' => 1,
                'value' => 20,
                'isDouble' => false,
                'isTriple' => false,
                'isBust' => false,
            ],
            [
                'playerId' => $playerA->getId(),
                'roundNumber' => 2,
                'throwNumber' => 1,
                'value' => 30,
                'isDouble' => false,
                'isTriple' => false,
                'isBust' => false,
            ],
            [
                'playerId' => $playerB->getId(),
                'roundNumber' => 2,
                'throwNumber' => 2,
                'value' => 40,
                'isDouble' => false,
                'isTriple' => false,
                'isBust' => false,
            ],
        ], $rows);
    }

    public function testAggregateHelpers(): void
    {
        $game = $this->createGame(GameStatus::Started);
        $round1 = $this->createRound($game, 1, finished: true);
        $round2 = $this->createRound($game, 2, finished: true);

        $player1 = $this->createUser('u1');
        $player2 = $this->createUser('u2');

        // Player 1: round 1 -> 10, 20 (avg 15); round 2 -> bust then 5 (avg (0+5)/2 = 2.5)
        $this->createThrow($game, $round1, $player1, 1, 10, 10);
        $this->createThrow($game, $round1, $player1, 2, 20, 30);
        $this->createThrow($game, $round2, $player1, 1, 50, 30, isBust: true);
        $this->createThrow($game, $round2, $player1, 2, 5, 35);

        // Player 2: round 1 -> 30; round 2 -> 40
        $this->createThrow($game, $round1, $player2, 1, 30, 30);
        $this->createThrow($game, $round2, $player2, 1, 40, 70);
        $this->em->flush();

        $averages = $this->repo->getRoundAveragesForGame($game->getGameId());
        $roundsPlayed = $this->repo->getRoundsPlayedForGame($game->getGameId());
        $lastRounds = $this->repo->getLastRoundNumberForGame($game->getGameId());
        $totals = $this->repo->getTotalScoreForGame($game->getGameId());

        $averages = array_map(
            static fn(array $row): array => [
                'playerId' => (int) $row['playerId'],
                'roundNumber' => (int) $row['roundNumber'],
                'average' => (float) $row['average'],
            ],
            $averages
        );

        usort($averages, static fn(array $a, array $b): int => [$a['roundNumber'], $a['playerId']] <=> [$b['roundNumber'], $b['playerId']]);

        self::assertSame([
            ['playerId' => $player1->getId(), 'roundNumber' => 1, 'average' => 15.0],
            ['playerId' => $player2->getId(), 'roundNumber' => 1, 'average' => 30.0],
            ['playerId' => $player1->getId(), 'roundNumber' => 2, 'average' => 27.5],
            ['playerId' => $player2->getId(), 'roundNumber' => 2, 'average' => 40.0],
        ], $averages);

        ksort($roundsPlayed);
        ksort($lastRounds);

        self::assertSame([
            $player1->getId() => 2,
            $player2->getId() => 2,
        ], $roundsPlayed);

        self::assertSame([
            $player1->getId() => 2,
            $player2->getId() => 2,
        ], $lastRounds);

        // bust throw counts as 0 for totals
        self::assertSame([
            $player1->getId() => 35.0,
            $player2->getId() => 70.0,
        ], $totals);
    }

    public function testGetPlayerStatisticsAggregatesFinishedRoundsPerPlayer(): void
    {
        $finishedGameA = $this->createGame(GameStatus::Finished);
        $finishedGameB = $this->createGame(GameStatus::Finished);
        $startedGame = $this->createGame(GameStatus::Started);

        $finishedRoundA1 = $this->createRound($finishedGameA, 1, finished: true);
        $finishedRoundA2 = $this->createRound($finishedGameA, 2, finished: true);
        $unfinishedRoundA3 = $this->createRound($finishedGameA, 3, finished: false);
        $finishedRoundB1 = $this->createRound($finishedGameB, 1, finished: true);
        $startedRound = $this->createRound($startedGame, 1, finished: true);

        $alice = $this->createUser('stats-alice');
        $alice->setDisplayName('Alice');
        $bob = $this->createUser('stats-bob');
        $bob->setDisplayName('Bob');
        $guest = $this->createUser('stats-guest');
        $guest->setDisplayName('Guest');
        $guest->setIsGuest(true);

        $this->createThrow($finishedGameA, $finishedRoundA1, $alice, 1, 10, 10);
        $this->createThrow($finishedGameA, $finishedRoundA1, $alice, 2, 20, 30);
        $this->createThrow($finishedGameA, $finishedRoundA2, $alice, 1, 50, 30, isBust: true);
        $this->createThrow($finishedGameA, $finishedRoundA2, $alice, 2, 5, 35);
        $this->createThrow($finishedGameB, $finishedRoundB1, $alice, 1, 30, 30);
        $this->createThrow($finishedGameA, $unfinishedRoundA3, $alice, 1, 60, 95);
        $this->createThrow($startedGame, $startedRound, $alice, 1, 40, 40);

        $this->createThrow($finishedGameA, $finishedRoundA1, $bob, 1, 15, 15);
        $this->createThrow($finishedGameA, $finishedRoundA1, $bob, 2, 15, 30);

        $this->createThrow($finishedGameA, $finishedRoundA1, $guest, 1, 25, 25);
        $this->em->flush();

        $rows = $this->repo->getPlayerStatistics(10, 0, 'average', 'DESC');
        $normalizedRows = array_map(static function (array $row): array {
            return [
                'playerId' => (int) $row['playerId'],
                'username' => (string) $row['username'],
                'gamesPlayed' => (int) $row['gamesPlayed'],
                'totalValue' => round((float) $row['totalValue'], 4),
                'roundsFinished' => (int) $row['roundsFinished'],
                'scoreAverage' => null !== $row['scoreAverage'] ? round((float) $row['scoreAverage'], 4) : null,
            ];
        }, $rows);

        self::assertSame([
            [
                'playerId' => $bob->getId(),
                'username' => 'Bob',
                'gamesPlayed' => 1,
                'totalValue' => 30.0,
                'roundsFinished' => 1,
                'scoreAverage' => 30.0,
            ],
            [
                'playerId' => $alice->getId(),
                'username' => 'Alice',
                'gamesPlayed' => 2,
                'totalValue' => 65.0,
                'roundsFinished' => 3,
                'scoreAverage' => 21.6667,
            ],
        ], $normalizedRows);
    }

    public function testGetPlayerStatisticsSupportsGamesPlayedSortingAndPagination(): void
    {
        $finishedGameA = $this->createGame(GameStatus::Finished);
        $finishedGameB = $this->createGame(GameStatus::Finished);
        $finishedGameC = $this->createGame(GameStatus::Finished);

        $roundA = $this->createRound($finishedGameA, 1, finished: true);
        $roundB = $this->createRound($finishedGameB, 1, finished: true);
        $roundC = $this->createRound($finishedGameC, 1, finished: true);

        $alice = $this->createUser('stats-sort-alice');
        $alice->setDisplayName('Alice sort');
        $bob = $this->createUser('stats-sort-bob');
        $bob->setDisplayName('Bob sort');

        $this->createThrow($finishedGameA, $roundA, $alice, 1, 20, 20);
        $this->createThrow($finishedGameB, $roundB, $alice, 1, 20, 20);
        $this->createThrow($finishedGameC, $roundC, $bob, 1, 25, 25);
        $this->em->flush();

        $rows = $this->repo->getPlayerStatistics(1, 0, 'gamesPlayed', 'DESC');

        self::assertCount(1, $rows);
        self::assertSame($alice->getId(), (int) $rows[0]['playerId']);
        self::assertSame('2', (string) $rows[0]['gamesPlayed']);
    }

    private function createGame(GameStatus $status): Game
    {
        $game = (new Game())
            ->setDate(new \DateTime())
            ->setStatus($status);

        $this->em->persist($game);

        return $game;
    }

    private function createRound(Game $game, int $roundNumber, bool $finished = false): Round
    {
        $round = (new Round())
            ->setGame($game)
            ->setRoundNumber($roundNumber)
            ->setStartedAt(new \DateTimeImmutable('-1 minute'));

        if ($finished) {
            $round->setFinishedAt(new \DateTimeImmutable());
        }

        $this->em->persist($round);

        return $round;
    }

    private function createUser(string $username): User
    {
        $user = (new User())
            ->setUsername($username)
            ->setEmail($username . '@test.dev')
            ->setPassword('secret');

        $this->em->persist($user);

        return $user;
    }

    private function createThrow(
        Game $game,
        Round $round,
        User $player,
        int $throwNumber,
        int $value,
        int $score,
        bool $isBust = false
    ): RoundThrows {
        $throw = (new RoundThrows())
            ->setGame($game)
            ->setRound($round)
            ->setPlayer($player)
            ->setThrowNumber($throwNumber)
            ->setValue($value)
            ->setIsBust($isBust)
            ->setScore($score)
            ->setTimestamp(new \DateTimeImmutable());

        $this->em->persist($throw);

        return $throw;
    }
}
