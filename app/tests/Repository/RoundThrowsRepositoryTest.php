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
