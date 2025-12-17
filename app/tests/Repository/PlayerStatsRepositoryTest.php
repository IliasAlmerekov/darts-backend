<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\PlayerStats;
use App\Repository\PlayerStatsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PlayerStatsRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private PlayerStatsRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->repository = static::getContainer()->get(PlayerStatsRepository::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager->close();
    }

    public function testPersistAndFind(): void
    {
        $stats = $this->createStats(
            wins: 10,
            winPercentage: 70,
            roundAverage: 45,
            highestCheckout: 120,
            gamesPlayed: 15
        );
        $this->entityManager->flush();

        $found = $this->repository->find($stats->getPlayerId());

        self::assertNotNull($found);
        self::assertSame(10, $found->getWins());
        self::assertSame(70, $found->getWinPercentage());
        self::assertSame(45, $found->getRoundAverage());
        self::assertSame(120, $found->getHighestCheckout());
        self::assertSame(15, $found->getGamesPlayed());
    }

    public function testUpdateValues(): void
    {
        $stats = $this->createStats(
            wins: 5,
            winPercentage: 50,
            roundAverage: 30,
            highestCheckout: 80,
            gamesPlayed: 8
        );
        $this->entityManager->flush();

        $stats->setWins(6)
            ->setWinPercentage(60)
            ->setRoundAverage(35)
            ->setHighestCheckout(100)
            ->setGamesPlayed(9);

        $this->entityManager->flush();
        $this->entityManager->clear();

        $reloaded = $this->repository->find($stats->getPlayerId());
        self::assertNotNull($reloaded);
        self::assertSame(6, $reloaded->getWins());
        self::assertSame(60, $reloaded->getWinPercentage());
        self::assertSame(35, $reloaded->getRoundAverage());
        self::assertSame(100, $reloaded->getHighestCheckout());
        self::assertSame(9, $reloaded->getGamesPlayed());
    }

    private function createStats(
        ?int $wins,
        int $winPercentage,
        ?int $roundAverage,
        ?int $highestCheckout,
        ?int $gamesPlayed
    ): PlayerStats {
        $stats = (new PlayerStats())
            ->setWins($wins)
            ->setWinPercentage($winPercentage)
            ->setRoundAverage($roundAverage)
            ->setHighestCheckout($highestCheckout)
            ->setGamesPlayed($gamesPlayed);

        $this->entityManager->persist($stats);

        return $stats;
    }
}
