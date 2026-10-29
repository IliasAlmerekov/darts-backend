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
}
