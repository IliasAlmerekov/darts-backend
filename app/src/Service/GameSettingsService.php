<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\GameSettingsRequest;
use App\Entity\Game;
use App\Enum\GameStatus;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;

/**
 * Service to update game settings in a safe way.
 *
 * @psalm-suppress UnusedClass Reason: service is auto-wired by the container and used through DI.
 */
final readonly class GameSettingsService
{
    private const ALLOWED_START_SCORES = [101, 201, 301, 401, 501];

    /**
     * @param EntityManagerInterface $entityManager
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
    public function updateSettings(Game $game, GameSettingsRequest $dto): void
    {
        $status = $game->getStatus();
        $isLobby = GameStatus::Lobby === $status;
        $isStarted = GameStatus::Started === $status;
        if (!$isLobby && !$isStarted) {
            throw new InvalidArgumentException('Settings can only be changed while the game is in the lobby or started.');
        }

        if (null === $dto->startScore && null === $dto->doubleOut && null === $dto->tripleOut) {
            throw new InvalidArgumentException('No settings provided to update.');
        }

        if (null !== $dto->startScore) {
            if ($isStarted) {
                throw new InvalidArgumentException('startScore cannot be changed after the game has started.');
            }
            $this->guardStartScore($dto->startScore);
            $game->setStartScore($dto->startScore);
        }

        if (null !== $dto->doubleOut) {
            $game->setDoubleOut($dto->doubleOut);
        }

        if (null !== $dto->tripleOut) {
            $game->setTripleOut($dto->tripleOut);
        }

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
            throw new InvalidArgumentException('startScore must be one of: 101, 201, 301, 401, 501.');
        }
    }
}
