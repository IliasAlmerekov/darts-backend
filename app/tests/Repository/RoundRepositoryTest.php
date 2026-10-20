<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Game;
use App\Entity\Round;
use App\Enum\GameStatus;
use App\Repository\RoundRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RoundRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private RoundRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->repository = static::getContainer()->get(RoundRepository::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager->close();
    }

    public function testCountFinishedRounds(): void
    {
        $game = $this->createGame();
        $this->createRound($game, roundNumber: 1, finished: true);
        $this->createRound($game, roundNumber: 2, finished: true);
        $this->createRound($game, roundNumber: 3, finished: false);
        $this->entityManager->flush();

        $count = $this->repository->countFinishedRounds($game->getGameId());

        self::assertSame(2, $count);
    }

    public function testExistsForGame(): void
    {
        $game = $this->createGame();
        $this->entityManager->flush();

        self::assertFalse($this->repository->existsForGame($game->getGameId()));

        $this->createRound($game, roundNumber: 1, finished: false);
        $this->entityManager->flush();

        self::assertTrue($this->repository->existsForGame($game->getGameId()));
    }

    public function testCountFinishedRoundsByGameIdsMatchesCountFinishedRoundsPerGame(): void
    {
        $first = $this->createGame();
        $second = $this->createGame();
        $withoutFinished = $this->createGame();
        $this->createRound($first, roundNumber: 1, finished: true);
        $this->createRound($first, roundNumber: 2, finished: true);
        $this->createRound($first, roundNumber: 3, finished: false);
        $this->createRound($second, roundNumber: 1, finished: true);
        $this->createRound($withoutFinished, roundNumber: 1, finished: false);
        $this->entityManager->flush();

        $counts = $this->repository->countFinishedRoundsByGameIds([
            (int) $first->getGameId(),
            (int) $second->getGameId(),
            (int) $withoutFinished->getGameId(),
        ]);
        ksort($counts);

        self::assertSame([(int) $first->getGameId() => 2, (int) $second->getGameId() => 1], $counts);
        self::assertSame([], $this->repository->countFinishedRoundsByGameIds([]));
    }

    private function createGame(): Game
    {
        $game = (new Game())
            ->setDate(new \DateTime())
            ->setStatus(GameStatus::Started);

        $this->entityManager->persist($game);

        return $game;
    }

    private function createRound(Game $game, int $roundNumber, bool $finished): Round
    {
        $round = (new Round())
            ->setGame($game)
            ->setRoundNumber($roundNumber)
            ->setStartedAt(new \DateTimeImmutable('-5 minutes'));

        if ($finished) {
            $round->setFinishedAt(new \DateTimeImmutable());
        }

        $this->entityManager->persist($round);

        return $round;
    }
}
