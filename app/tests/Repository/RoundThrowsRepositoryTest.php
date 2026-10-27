<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Dto\ParticipantTotalsDto;
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

        // Player 1: round 1 -> 10, 20 (two darts); round 2 -> a bust dart, then 5
        $this->createThrow($game, $round1, $player1, 1, 10, 10);
        $this->createThrow($game, $round1, $player1, 2, 20, 30);
        $this->createThrow($game, $round2, $player1, 1, 50, 30, isBust: true);
        $this->createThrow($game, $round2, $player1, 2, 5, 35);

        // Player 2: round 1 -> 30; round 2 -> 40
        $this->createThrow($game, $round1, $player2, 1, 30, 30);
        $this->createThrow($game, $round2, $player2, 1, 40, 70);
        $this->em->flush();

        $averages = $this->repo->getRoundAveragesForGame($game->getGameId());
        $totals = $this->repo->getParticipantTotalsForGames([(int) $game->getGameId()])[(int) $game->getGameId()];

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

        // Player 1's second turn holds a bust, so it scores 0 and counts as 3 darts.
        self::assertEquals(new ParticipantTotalsDto(turns: 2, dartsThrown: 5, totalScore: 30.0), $totals[(int) $player1->getId()]);
        self::assertEquals(new ParticipantTotalsDto(turns: 2, dartsThrown: 2, totalScore: 70.0), $totals[(int) $player2->getId()]);
    }

    public function testGetPlayerStatisticsAggregatesTheThreeDartAveragePerPlayer(): void
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
                'dartsThrown' => (int) $row['dartsThrown'],
                'scoreAverage' => null !== $row['scoreAverage'] ? round((float) $row['scoreAverage'], 4) : null,
            ];
        }, $rows);

        // Alice: 30 + 0 (bust turn) + 60 + 30 points over 2 + 3 + 1 + 1 darts, so the open round
        // of the finished game counts and the started game does not.
        self::assertSame([
            [
                'playerId' => $alice->getId(),
                'username' => 'Alice',
                'gamesPlayed' => 2,
                'totalValue' => 120.0,
                'dartsThrown' => 7,
                'scoreAverage' => 51.4286,
            ],
            [
                'playerId' => $bob->getId(),
                'username' => 'Bob',
                'gamesPlayed' => 1,
                'totalValue' => 30.0,
                'dartsThrown' => 2,
                'scoreAverage' => 45.0,
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

    public function testGetParticipantTotalsForGamesAppliesTheTurnRules(): void
    {
        $first = $this->createGame(GameStatus::Finished);
        $second = $this->createGame(GameStatus::Finished);
        $first1 = $this->createRound($first, 1, finished: true);
        $first2 = $this->createRound($first, 2, finished: true);
        $first3 = $this->createRound($first, 3);
        $second1 = $this->createRound($second, 1);
        $alice = $this->createUser('totals-alice');
        $bob = $this->createUser('totals-bob');
        // Turn 1: two darts, 60 points. Turn 2: a bust on the first dart. Turn 3: one dart of an open round.
        $this->createThrow($first, $first1, $alice, 1, 20, 281);
        $this->createThrow($first, $first1, $alice, 2, 40, 241);
        $this->createThrow($first, $first2, $alice, 1, 60, 241, isBust: true);
        $this->createThrow($first, $first3, $alice, 1, 11, 230);
        // A bust on the last dart voids the two darts before it.
        $this->createThrow($first, $first1, $bob, 1, 5, 296);
        $this->createThrow($first, $first1, $bob, 2, 7, 289);
        $this->createThrow($first, $first1, $bob, 3, 50, 289, isBust: true);
        $this->createThrow($second, $second1, $alice, 1, 7, 294);
        $this->em->flush();
        $firstId = (int) $first->getGameId();
        $secondId = (int) $second->getGameId();

        $totals = $this->repo->getParticipantTotalsForGames([$firstId, $secondId]);

        self::assertEquals(new ParticipantTotalsDto(turns: 3, dartsThrown: 6, totalScore: 71.0), $totals[$firstId][(int) $alice->getId()]);
        self::assertEquals(new ParticipantTotalsDto(turns: 1, dartsThrown: 3, totalScore: 0.0), $totals[$firstId][(int) $bob->getId()]);
        self::assertEquals(new ParticipantTotalsDto(turns: 1, dartsThrown: 1, totalScore: 7.0), $totals[$secondId][(int) $alice->getId()]);
        self::assertSame([], $this->repo->getParticipantTotalsForGames([]));
    }

    public function testFindThrowsInCountedGamesIncludesTheOpenRoundOfAFinishedGame(): void
    {
        $finished = $this->createGame(GameStatus::Finished);
        $started = $this->createGame(GameStatus::Started);
        $open1 = $this->createRound($finished, 1);
        $open2 = $this->createRound($finished, 2);
        $startedRound = $this->createRound($started, 1);
        $alice = $this->createUser('counted-alice');
        // Every round Alice threw in is unfinished: only the new rule counts her throws.
        $this->createThrow($finished, $open1, $alice, 1, 20, 281);
        $this->createThrow($finished, $open2, $alice, 1, 25, 256);
        $this->createThrow($started, $startedRound, $alice, 1, 9, 292);
        $this->em->flush();

        $rows = $this->repo->findThrowsInCountedGamesForPlayer((int) $alice->getId());

        self::assertSame([20, 25], array_column($rows, 'value'));
    }

    public function testPlayerStatisticsIncludeAPlayerWhoseOnlyTurnsSitInUnfinishedRounds(): void
    {
        $game = $this->createGame(GameStatus::Finished);
        $open = $this->createRound($game, 1);
        $alice = $this->createUser('open-only-alice');
        $this->createThrow($game, $open, $alice, 1, 20, 281);
        $this->createThrow($game, $open, $alice, 2, 40, 241);
        $this->em->flush();

        $list = array_values(array_filter(
            $this->repo->getPlayerStatistics(1000, 0),
            static fn(array $row): bool => (int) $row['playerId'] === $alice->getId()
        ));
        $single = $this->repo->getPlayerStatisticsForPlayer((int) $alice->getId());

        self::assertCount(1, $list);
        self::assertNotNull($single);
        self::assertSame($list[0], $single);
        self::assertSame('2', (string) $single['dartsThrown']);
        self::assertSame(90.0, (float) $single['scoreAverage']);
    }

    public function testCountPlayersWithStatisticsCountsRegisteredPlayersWhoThrewInAFinishedGame(): void
    {
        $before = $this->repo->countPlayersWithStatistics();
        $finished = $this->createGame(GameStatus::Finished);
        $started = $this->createGame(GameStatus::Started);
        $open = $this->createRound($finished, 1);
        $startedRound = $this->createRound($started, 1);
        $alice = $this->createUser('count-alice');
        $bob = $this->createUser('count-bob');
        $guest = $this->createUser('count-guest')->setIsGuest(true);
        $this->createThrow($finished, $open, $alice, 1, 20, 281);
        $this->createThrow($finished, $open, $guest, 1, 20, 281);
        $this->createThrow($started, $startedRound, $bob, 1, 20, 281);
        $this->em->flush();

        self::assertSame($before + 1, $this->repo->countPlayersWithStatistics());
    }

    public function testGetPlayerStatisticsForPlayerReturnsThatPlayersListRow(): void
    {
        $game = $this->createGame(GameStatus::Finished);
        $round = $this->createRound($game, 1, finished: true);
        $alice = $this->createUser('single-stats-alice');
        $bob = $this->createUser('single-stats-bob');
        $nobody = $this->createUser('single-stats-nobody');
        $this->createThrow($game, $round, $alice, 1, 30, 271);
        $this->createThrow($game, $round, $bob, 1, 12, 289);
        $this->em->flush();

        $listRows = array_values(array_filter(
            $this->repo->getPlayerStatistics(100, 0),
            static fn(array $row): bool => (int) $row['playerId'] === $alice->getId()
        ));

        self::assertSame($listRows[0], $this->repo->getPlayerStatisticsForPlayer((int) $alice->getId()));
        self::assertSame('90.0000', $listRows[0]['scoreAverage']);
        self::assertNull($this->repo->getPlayerStatisticsForPlayer((int) $nobody->getId()));
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
