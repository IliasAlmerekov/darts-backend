<?php

namespace App\Repository;

use App\Entity\Game;
use App\Enum\GameStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Game>
 */
class GameRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Game::class);
    }

    //    /**
    //     * @return Game[] Returns an array of Game objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('g')
    //            ->andWhere('g.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('g.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    public function findOneByGameId(int $gameId): ?Game
    {
        return $this->createQueryBuilder('g')
            ->andWhere('g.gameId = :gameId')
            ->setParameter('gameId', $gameId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findHighestGameId(): ?int
    {
        $result = $this->createQueryBuilder('g')
            ->select('MAX(g.gameId)')
            ->getQuery()
            ->getSingleScalarResult();

        return $result ? (int)$result : null;
    }

    public function countFinishedGames(): int{
        return (int) $this->createQueryBuilder('g')
            ->select('COUNT(g.gameId)')
            ->andWhere('g.status = :status')
            ->setParameter('status', GameStatus::Finished)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
