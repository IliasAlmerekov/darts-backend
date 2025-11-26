<?php

namespace App\Repository;

use App\Entity\RoundThrows;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RoundThrows>
 */
class RoundThrowsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RoundThrows::class);
    }

    /**
     * Latest throw for game (scalar data)
     */
    public function findLatestForGame(int $gameId): ?array
    {
        return $this->createQueryBuilder('rt')
            ->select(
                'rt.throwId AS id',
                'rt.throwNumber AS throwNumber',
                'rt.value AS value',
                'rt.isDouble AS isDouble',
                'rt.isTriple AS isTriple',
                'rt.isBust AS isBust',
                'rt.score AS score',
                'rt.timestamp AS timestamp',
                'r.roundNumber AS roundNumber',
                'u.id AS playerId',
                'u.username AS playerName'
            )
            ->innerJoin('rt.round', 'r')
            ->innerJoin('rt.player', 'u')
            ->andWhere('rt.game = :gameId')
            ->setParameter('gameId', $gameId)
            ->orderBy('rt.throwId', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findEntityLatestForGame(int $gameId): ?RoundThrows
    {
        return $this->createQueryBuilder('rt')
            ->andWhere('rt.game = :gameId')
            ->setParameter('gameId', $gameId)
            ->orderBy('rt.throwId', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findLatestForGameAndPlayer(int $gameId, int $playerId): ?RoundThrows
    {
        return $this->createQueryBuilder('rt')
            ->andWhere('rt.game = :gameId')
            ->andWhere('rt.player = :playerId')
            ->setParameter('gameId', $gameId)
            ->setParameter('playerId', $playerId)
            ->orderBy('rt.throwId', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
