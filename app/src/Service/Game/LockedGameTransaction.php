<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Game;
use App\Exception\Game\GameNotFoundException;
use App\Repository\GameRepositoryInterface;
use App\Service\Persistence\RetryingTransactionInterface;
use App\Service\Security\GameAccessServiceInterface;
use Closure;
use Doctrine\ORM\EntityManagerInterface;
use Override;

/**
 * @psalm-suppress UnusedClass Reason: service is wired via DI.
 */
final readonly class LockedGameTransaction implements LockedGameTransactionInterface
{
    /**
     * @param GameAccessServiceInterface   $gameAccessService
     * @param EntityManagerInterface       $entityManager
     * @param RetryingTransactionInterface $retryingTransaction
     * @param GameRepositoryInterface      $gameRepository
     *
     * @psalm-suppress PossiblyUnusedMethod Reason: constructor is used by Symfony autowiring.
     */
    public function __construct(
        private GameAccessServiceInterface $gameAccessService,
        private EntityManagerInterface $entityManager,
        private RetryingTransactionInterface $retryingTransaction,
        private GameRepositoryInterface $gameRepository,
    ) {
    }

    /**
     * @template T
     *
     * @param int             $gameId
     * @param Closure(Game):T $operation
     *
     * @return T
     */
    #[Override]
    public function run(int $gameId, Closure $operation): mixed
    {
        $this->gameAccessService->assertAdmin();

        return $this->entityManager->wrapInTransaction(fn (): mixed => $operation($this->lock($gameId)));
    }

    /**
     * @template T
     *
     * @param int             $gameId
     * @param Closure(Game):T $operation
     *
     * @return T
     */
    #[Override]
    public function runWithRetry(int $gameId, Closure $operation): mixed
    {
        $this->gameAccessService->assertAdmin();

        return $this->retryingTransaction->run(fn (): mixed => $operation($this->lock($gameId)));
    }

    /**
     * @param int $gameId
     *
     * @return Game
     */
    private function lock(int $gameId): Game
    {
        return $this->gameRepository->findOneByGameIdWithSeatsForUpdate($gameId) ?? throw new GameNotFoundException();
    }
}
