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
