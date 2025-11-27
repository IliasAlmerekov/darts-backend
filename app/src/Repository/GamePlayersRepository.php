<?php

namespace App\Repository;

use App\Entity\GamePlayers;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GamePlayers>
 */
class GamePlayersRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GamePlayers::class);
    }

    /**
     * Find players with user information for a specific game
     * @param int $gameId
     * @return array
     */
    public function findPlayersWithUserInfo(int $gameId): array
    {
        return $this->createQueryBuilder('gamePlayer')
            ->select('u.id as id', 'u.username as name')
            ->innerJoin('gamePlayer.player', 'u')
            ->andWhere('gamePlayer.game = :gameId')
            ->setParameter('gameId', $gameId)
            ->getQuery()
            ->getResult();
    }

    /**
     * Check if player already joined the game
     * @param int $gameId
     * @param int $playerId
     * @return bool
     */
    public function isPlayerInGame(int $gameId, int $playerId): bool
    {
        $count = $this->count([
            'game' => $gameId,
            'player' => $playerId
        ]);

        return $count > 0;
    }

    /**
     * @return GamePlayers[]
     */
    public function findByGameId(int $gameId): array
    {
        return $this->createQueryBuilder('gp')
            ->andWhere('gp.game = :gameId')
            ->setParameter('gameId', $gameId)
            ->getQuery()
            ->getResult();
    }

    /**
     * Counts how many players in a game have already finished (score == 0).
     */
    public function countFinishedPlayers(int $gameId): int
    {
        return (int) $this->createQueryBuilder('gp')
            ->select('COUNT(gp.gamePlayerId)')
            ->andWhere('gp.game = :gameId')
            ->andWhere('gp.score = 0')
            ->setParameter('gameId', $gameId)
            ->getQuery()
            ->getSingleScalarResult();
    }


    //    /**
    //     * @return GamePlayers[] Returns an array of GamePlayers objects
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

    //    public function findOneBySomeField($value): ?GamePlayers
    //    {
    //        return $this->createQueryBuilder('g')
    //            ->andWhere('g.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
