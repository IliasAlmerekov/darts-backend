<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Dto\GameSettingsRequest;
use App\Entity\Game;
use App\Exception\Game\InvalidOutModeException;
use App\Exception\Game\InvalidStartScoreException;
use App\Exception\Game\NoSettingsProvidedException;
use App\Exception\Game\SettingsNotEditableException;
use App\Exception\Game\StartScoreCannotBeChangedAfterStartException;
use App\Enum\GameStatus;
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
    private const ALLOWED_START_SCORES = [101, 201, 301, 401, 501];

    /**
     * @param EntityManagerInterface $entityManager
     *
     * @psalm-suppress PossiblyUnusedMethod Reason: constructor is used by Symfony autowiring.
     */
    public function __construct(private EntityManagerInterface $entityManager)
    {
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
        $status = $game->getStatus();
        $isLobby = GameStatus::Lobby === $status;
        $isStarted = GameStatus::Started === $status;
        if (!$isLobby && !$isStarted) {
            throw new SettingsNotEditableException();
        }

        if (null === $dto->startScore && null === $dto->outMode && null === $dto->doubleOut && null === $dto->tripleOut) {
            throw new NoSettingsProvidedException();
        }

        if (null !== $dto->startScore) {
            if ($isStarted) {
                throw new StartScoreCannotBeChangedAfterStartException();
            }
            $this->guardStartScore($dto->startScore);
            $game->setStartScore($dto->startScore);
        }

        $doubleOut = $game->isDoubleOut();
        $tripleOut = $game->isTripleOut();

        if (null !== $dto->outMode) {
            $outMode = strtolower($dto->outMode);
            if ('singleout' === $outMode) {
                $doubleOut = false;
                $tripleOut = false;
            } elseif ('doubleout' === $outMode) {
                $doubleOut = true;
                $tripleOut = false;
            } elseif ('tripleout' === $outMode) {
                $doubleOut = false;
                $tripleOut = true;
            } else {
                throw new InvalidOutModeException();
            }
        } else {
            if (null !== $dto->doubleOut) {
                $doubleOut = $dto->doubleOut;
            }

            if (null !== $dto->tripleOut) {
                $tripleOut = $dto->tripleOut;
            }
        }

        $game->setDoubleOut($doubleOut);
        $game->setTripleOut($tripleOut);

        $this->entityManager->flush();
    }

    /**
     * @param int $startScore
     *
     * @return void
     */
    private function guardStartScore(int $startScore): void
    {
        if (!in_array($startScore, self::ALLOWED_START_SCORES, true)) {
            throw new InvalidStartScoreException();
        }
    }
}
