<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Game;
use App\Enum\GameStatus;
use App\Repository\GameRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GameRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private GameRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->repository = static::getContainer()->get(GameRepository::class);
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

    private function createGame(GameStatus $status): Game
    {
        $game = (new Game())
            ->setDate(new \DateTime())
            ->setStatus($status);

        $this->entityManager->persist($game);

        return $game;
    }
}
