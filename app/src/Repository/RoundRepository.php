<?php

namespace App\Repository;

use App\Entity\Round;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Round>
 */
final class RoundRepository extends ServiceEntityRepository implements RoundRepositoryInterface
{
    /**
     * @param ManagerRegistry $registry
     *
     * @psalm-suppress PossiblyUnusedMethod
     * @psalm-suppress UnusedParam
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Round::class);
    }

    /**
     * @param int $gameId
     *
     * @return int
     */
    #[\Override]
    public function countFinishedRounds(int $gameId): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(DISTINCT r.roundNumber)')
            ->andWhere('r.game = :gameId')
            ->andWhere('r.finishedAt IS NOT NULL')
            ->setParameter('gameId', $gameId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @param list<int> $gameIds
     *
     * @return array<int, int> countFinishedRounds() per game id; games without one are missing
     */
    #[\Override]
    public function countFinishedRoundsByGameIds(array $gameIds): array
    {
        if ([] === $gameIds) {
            return [];
        }

        /** @var list<array{gameId:int|string,finishedRounds:int|string}> $rows */
        $rows = $this->createQueryBuilder('r')
            ->select('IDENTITY(r.game) AS gameId', 'COUNT(DISTINCT r.roundNumber) AS finishedRounds')
            ->andWhere('r.game IN (:gameIds)')
            ->andWhere('r.finishedAt IS NOT NULL')
            ->setParameter('gameIds', $gameIds)
            ->groupBy('gameId')
            ->getQuery()
            ->getArrayResult();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['gameId']] = (int) $row['finishedRounds'];
        }

        return $map;
    }

    /**
     * @param int $gameId
     *
     * @return bool
     */
    #[\Override]
    public function existsForGame(int $gameId): bool
    {
        return null !== $this->createQueryBuilder('r')
            ->select('r.roundId')
            ->andWhere('r.game = :gameId')
            ->setParameter('gameId', $gameId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    //    /**
     //     * @return Round[] Returns an array of Round objects
     //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('r')
    //            ->andWhere('r.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('r.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Round
    //    {
    //        return $this->createQueryBuilder('r')
    //            ->andWhere('r.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
