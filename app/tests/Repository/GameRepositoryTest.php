<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Game;
use App\Entity\GamePlayers;
use App\Entity\Round;
use App\Entity\RoundThrows;
use App\Entity\User;
use App\Enum\GameStatus;
use App\Repository\GameRepository;
use App\Service\Game\GameFinishServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GameRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private GameRepository $repository;
    private GameFinishServiceInterface $gameFinishService;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->repository = static::getContainer()->get(GameRepository::class);
        $this->gameFinishService = static::getContainer()->get(GameFinishServiceInterface::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager->close();
    }

    public function testFindOneByGameId(): void
    {
        $game = $this->createGame(GameStatus::Started);
        $this->entityManager->flush();

        $found = $this->repository->findOneByGameId($game->getGameId());

        self::assertNotNull($found);
        self::assertSame($game->getGameId(), $found->getGameId());
        self::assertSame(GameStatus::Started, $found->getStatus());
    }

    public function testFindHighestGameId(): void
    {
        $first = $this->createGame(GameStatus::Lobby);
        $second = $this->createGame(GameStatus::Lobby);
        $this->entityManager->flush();

        $highest = $this->repository->findHighestGameId();

        self::assertSame($second->getGameId(), $highest);
        self::assertGreaterThan($first->getGameId(), $second->getGameId());
    }

    public function testCountFinishedGames(): void
    {
        $this->createGame(GameStatus::Finished);
        $this->createGame(GameStatus::Finished);
        $this->createGame(GameStatus::Started);
        $this->entityManager->flush();

        $count = $this->repository->countFinishedGames();

        self::assertSame(2, $count);
    }

    public function testFindFinishedRespectsLimitOffsetAndOrder(): void
    {
        $finishedOne = $this->createGame(GameStatus::Finished);
        $finishedTwo = $this->createGame(GameStatus::Finished);
        $this->createGame(GameStatus::Started);
        $this->entityManager->flush();

        $result = $this->repository->findFinished(limit: 1, offset: 0);
        self::assertCount(1, $result);
        self::assertSame($finishedTwo->getGameId(), $result[0]->getGameId());

        $secondPage = $this->repository->findFinished(limit: 1, offset: 1);
        self::assertCount(1, $secondPage);
        self::assertSame($finishedOne->getGameId(), $secondPage[0]->getGameId());
    }

    public function testFindFinishedOverviewReturnsAggregatedOverviewRows(): void
    {
        $finishedGame = $this->createGame(GameStatus::Finished, new \DateTime('2026-03-09 00:00:00'));
        $winner = $this->createUser('winner-user', 'Winner Snapshot');
        $otherPlayer = $this->createUser('other-user', 'Other Snapshot');
        $finishedGame->setWinner($winner);
        $finishedGame->setFinishedAt(new \DateTimeImmutable('2026-03-09 12:34:56'));

        $winnerGamePlayer = $this->createGamePlayer($finishedGame, $winner, position: 1, score: 0);
        $winnerGamePlayer->setDisplayNameSnapshot('Winner Snapshot');
        $this->createGamePlayer($finishedGame, $otherPlayer, position: 2, score: 40);

        $roundOne = $this->createRound($finishedGame, 1);
        $roundTwo = $this->createRound($finishedGame, 2);
        $this->createThrow($finishedGame, $roundOne, $winner, throwNumber: 1, value: 60, score: 241);
        $this->createThrow($finishedGame, $roundTwo, $winner, throwNumber: 1, value: 181, score: 0);
        $this->createThrow($finishedGame, $roundTwo, $otherPlayer, throwNumber: 1, value: 20, score: 20);

        $startedGame = $this->createGame(GameStatus::Started, new \DateTime('2026-03-10 00:00:00'));
        $this->createGamePlayer($startedGame, $this->createUser('ignored-user'), position: 1, score: 301);

        $this->entityManager->flush();

        $result = $this->repository->findFinishedOverview(limit: 10, offset: 0);

        self::assertCount(1, $result);
        self::assertSame([
            'id' => $finishedGame->getGameId(),
            'date' => '2026-03-09T00:00:00+00:00',
            'finishedAt' => '2026-03-09T12:34:56+00:00',
            'playersCount' => 2,
            'winnerName' => 'Winner Snapshot',
            'winnerId' => $winner->getId(),
            'winnerRounds' => 2,
        ], $result[0]);
    }

    public function testFindFinishedOverviewMatchesLegacySummaryFields(): void
    {
        $finishedGame = $this->createGame(GameStatus::Finished, new \DateTime('2026-03-08 00:00:00'));
        $winner = $this->createUser('legacy-winner', 'Legacy Winner');
        $runnerUp = $this->createUser('legacy-runner-up', 'Legacy Runner Up');
        $finishedGame->setWinner($winner);
        $finishedGame->setFinishedAt(new \DateTimeImmutable('2026-03-08 18:15:00'));

        $winnerGamePlayer = $this->createGamePlayer($finishedGame, $winner, position: 1, score: 0);
        $winnerGamePlayer->setDisplayNameSnapshot('Snapshot Winner');
        $this->createGamePlayer($finishedGame, $runnerUp, position: 2, score: 24);

        $roundOne = $this->createRound($finishedGame, 1);
        $roundTwo = $this->createRound($finishedGame, 2);
        $roundThree = $this->createRound($finishedGame, 3);

        $this->createThrow($finishedGame, $roundOne, $winner, throwNumber: 1, value: 60, score: 241);
        $this->createThrow($finishedGame, $roundTwo, $winner, throwNumber: 1, value: 100, score: 141);
        $this->createThrow($finishedGame, $roundThree, $winner, throwNumber: 1, value: 141, score: 0);

        $this->createThrow($finishedGame, $roundOne, $runnerUp, throwNumber: 1, value: 45, score: 256);
        $this->createThrow($finishedGame, $roundTwo, $runnerUp, throwNumber: 1, value: 60, score: 196);
        $this->createThrow($finishedGame, $roundThree, $runnerUp, throwNumber: 1, value: 172, score: 24);

        $this->entityManager->flush();

        $summary = $this->gameFinishService->getGameStats($finishedGame);
        $overview = $this->repository->findFinishedOverview(limit: 10, offset: 0);

        self::assertCount(1, $overview);
        self::assertSame($summary->winner?->username, $overview[0]['winnerName']);
        self::assertSame(count($summary->finishedPlayers), $overview[0]['playersCount']);
        self::assertSame($summary->winnerRoundsPlayed, $overview[0]['winnerRounds']);
    }

    private function createGame(GameStatus $status, ?\DateTime $date = null): Game
    {
        $game = (new Game())
            ->setDate($date ?? new \DateTime())
            ->setStatus($status);

        $this->entityManager->persist($game);

        return $game;
    }

    private function createUser(string $username, ?string $displayName = null): User
    {
        $user = (new User())
            ->setUsername($username)
            ->setEmail($username . '@test.dev')
            ->setPassword('secret');

        if (null !== $displayName) {
            $user->setDisplayName($displayName);
        }

        $this->entityManager->persist($user);

        return $user;
    }

    private function createGamePlayer(Game $game, User $user, int $position, int $score): GamePlayers
    {
        $gamePlayer = (new GamePlayers())
            ->setGame($game)
            ->setPlayer($user)
            ->setPosition($position)
            ->setScore($score);

        $this->entityManager->persist($gamePlayer);

        return $gamePlayer;
    }

    private function createRound(Game $game, int $roundNumber): Round
    {
        $round = (new Round())
            ->setGame($game)
            ->setRoundNumber($roundNumber)
            ->setStartedAt(new \DateTimeImmutable('-1 minute'))
            ->setFinishedAt(new \DateTimeImmutable());

        $this->entityManager->persist($round);

        return $round;
    }

    private function createThrow(Game $game, Round $round, User $player, int $throwNumber, int $value, int $score): RoundThrows
    {
        $throw = (new RoundThrows())
            ->setGame($game)
            ->setRound($round)
            ->setPlayer($player)
            ->setThrowNumber($throwNumber)
            ->setValue($value)
            ->setScore($score)
            ->setTimestamp(new \DateTimeImmutable());

        $this->entityManager->persist($throw);

        return $throw;
    }
}
