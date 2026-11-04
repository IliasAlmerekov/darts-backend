<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Game;

use App\Enum\OutRule;

use App\Dto\GameSettingsRequest;
use App\Entity\Game;
use App\Exception\Game\ConflictingOutRulesException;
use App\Exception\Game\GameNotFoundException;
use App\Exception\Game\NoSettingsProvidedException;
use App\Exception\Game\OutRuleCannotBeChangedAfterFirstThrowException;
use App\Exception\Game\SettingsNotEditableException;
use App\Exception\Game\StartScoreCannotBeChangedAfterStartException;
use App\Enum\GameStatus;
use App\Repository\RoundThrowsRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Override;

/**
 * Service to update game settings in a safe way.
 *
 * @psalm-suppress UnusedClass Reason: service is auto-wired by the container and used through DI.
 * @psalm-suppress PossiblyUnusedMethod Reason: constructor is used by Symfony autowiring.
 */
final readonly class GameSettingsService implements GameSettingsServiceInterface
{
    /**
     * @param EntityManagerInterface         $entityManager
     * @param RoundThrowsRepositoryInterface $roundThrowsRepository
     * @param LockedGameTransactionInterface $lockedGameTransaction
     *
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RoundThrowsRepositoryInterface $roundThrowsRepository,
        private LockedGameTransactionInterface $lockedGameTransaction,
    ) {
    }

    /**
     * @param Game                $game
     * @param GameSettingsRequest $dto
     *
     * @return void
     */
    #[Override]
    public function updateSettings(Game $game, GameSettingsRequest $dto): void
    {
        // The route loaded the game before this transaction: check the committed status, not that snapshot.
        $gameId = $game->getGameId() ?? throw new GameNotFoundException();
        $this->lockedGameTransaction->run($gameId, function (Game $game) use ($dto): void {
            $this->applySettings($game, $dto);
        });
    }

    /**
     * @param Game                $game
     * @param GameSettingsRequest $dto
     *
     * @return void
     */
    #[Override]
    public function applySettings(Game $game, GameSettingsRequest $dto): void
    {
        $status = $game->getStatus();
        $isLobby = GameStatus::Lobby === $status;
        $isStarted = GameStatus::Started === $status;
        if (!$isLobby && !$isStarted) {
            throw new SettingsNotEditableException();
        }

        if (null === $dto->startScore && null === $dto->outMode && null === $dto->doubleOut && null === $dto->tripleOut) {
            throw new NoSettingsProvidedException();
        }

        if (null !== $dto->startScore && $isStarted) {
            throw new StartScoreCannotBeChangedAfterStartException();
        }

        $sendsOutRule = null !== $dto->outMode || null !== $dto->doubleOut || null !== $dto->tripleOut;
        if ($sendsOutRule && $isStarted && $this->roundThrowsRepository->count(['game' => $game]) > 0) {
            throw new OutRuleCannotBeChangedAfterFirstThrowException();
        }

        // outMode takes precedence over the flags, so a sent conflicting pair is rejected before it can hide it.
        ConflictingOutRulesException::assertCompatible(true === $dto->doubleOut, true === $dto->tripleOut);

        $doubleOut = $game->isDoubleOut();
        $tripleOut = $game->isTripleOut();

        if (null !== $dto->outMode) {
            $doubleOut = 'doubleout' === $dto->outMode;
            $tripleOut = 'tripleout' === $dto->outMode;
        } else {
            if (null !== $dto->doubleOut) {
                $doubleOut = $dto->doubleOut;
            }

            if (null !== $dto->tripleOut) {
                $tripleOut = $dto->tripleOut;
            }
        }

        ConflictingOutRulesException::assertCompatible($doubleOut, $tripleOut);

        if (null !== $dto->startScore) {
            $game->setStartScore($dto->startScore);
        }
        $game->setOutRule(OutRule::fromFlags($doubleOut, $tripleOut));

        $this->entityManager->flush();
    }
}
