<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Player;

use App\Entity\Game;
use App\Entity\GamePlayers;
use App\Entity\User;
use App\Exception\Game\InvalidPlayerOrderException;
use App\Repository\GamePlayersRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Override;

/**
 * Service to handle player management.
 * This class is responsible for adding and removing players from games.
 */
final readonly class PlayerManagementService implements PlayerManagementServiceInterface
{
    /**
     * @param GamePlayersRepositoryInterface $gamePlayersRepository
     * @param EntityManagerInterface         $entityManager
     */
    public function __construct(private GamePlayersRepositoryInterface $gamePlayersRepository, private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @param int $gameId
     * @param int $playerId
     *
     * @return bool
     */
    #[Override]
    public function removePlayer(int $gameId, int $playerId): bool
    {
        $gamePlayer = $this->gamePlayersRepository->findOneBy(
            [
                'game' => $gameId,
                'player' => $playerId,
            ]
        );
        if (null === $gamePlayer) {
            return false;
        }

        $this->entityManager->remove($gamePlayer);
        $this->entityManager->flush();

        return true;
    }

    /**
     * @param int      $gameId
     * @param int      $playerId
     * @param int|null $position
     *
     * @throws ORMException
     *
     * @return GamePlayers
     *
     * @psalm-suppress PossiblyUnusedReturnValue
     */
    #[Override]
    public function addPlayer(int $gameId, int $playerId, ?int $position = null): GamePlayers
    {
        /** @var User|null $player */
        $player = $this->entityManager->getReference(User::class, $playerId);
        $gamePlayer = new GamePlayers();
        $gamePlayer->setGame($this->entityManager->getReference(Game::class, $gameId));
        $gamePlayer->setPlayer($player);
        $gamePlayer->setDisplayNameSnapshot($this->resolveDisplayNameSnapshot($player, $playerId));
        $gamePlayer->setPosition($this->resolvePosition($position, $gameId));
        $this->entityManager->persist($gamePlayer);
        $this->entityManager->flush();

        return $gamePlayer;
    }

    /**
     * Copy players from one game to another. If a filter list is provided, only those players are copied.
     *
     * @param int            $fromGameId
     * @param int            $toGameId
     * @param list<int>|null $playerIds
     *
     * @throws ORMException
     *
     * @return void
     */
    #[Override]
    public function copyPlayers(int $fromGameId, int $toGameId, ?array $playerIds = null): void
    {
        $oldGamePlayers = $this->gamePlayersRepository->findByGameId($fromGameId);
        $filter = $playerIds;
        $orderIndex = 0;
        foreach ($oldGamePlayers as $oldGamePlayer) {
            $player = $oldGamePlayer->getPlayer();
            $playerId = $player?->getId();
            if (null !== $playerId && (null === $filter || in_array($playerId, $filter, true))) {
                $position = $oldGamePlayer->getPosition();
                if (!is_int($position)) {
                    $orderIndex++;
                    $position = $orderIndex;
                } else {
                    $orderIndex = max($orderIndex, $position);
                }

                $this->addPlayer($toGameId, $playerId, $position);
            }
        }
    }

    /**
     * @param int                                           $gameId
     * @param list<array{playerId:int, position:int}>|array $positions
     *
     * @return void
     */
    #[Override]
    public function updatePlayerPositions(int $gameId, array $positions): void
    {
        if ([] === $positions) {
            return;
        }

        $seenPlayerIds = [];
        $seenPositions = [];
        foreach ($positions as $orderItem) {
            $playerId = $orderItem['playerId'] ?? null;
            $position = $orderItem['position'] ?? null;
            if (!is_int($playerId) || !is_int($position)) {
                continue;
            }

            if (isset($seenPlayerIds[$playerId])) {
                throw new InvalidPlayerOrderException(sprintf('Duplicate playerId in positions payload: %d.', $playerId));
            }
            if (isset($seenPositions[$position])) {
                throw new InvalidPlayerOrderException(sprintf('Duplicate position in positions payload: %d.', $position));
            }

            $seenPlayerIds[$playerId] = true;
            $seenPositions[$position] = true;
        }

        $gamePlayers = $this->gamePlayersRepository->findBy(['game' => $gameId]);
        $playersById = [];
        $finalPositionsByPlayerId = [];
        foreach ($gamePlayers as $gamePlayer) {
            $playerId = $gamePlayer->getPlayer()?->getId();
            if (null !== $playerId) {
                $playersById[$playerId] = $gamePlayer;
                $existingPosition = $gamePlayer->getPosition();
                if (null !== $existingPosition) {
                    $finalPositionsByPlayerId[$playerId] = $existingPosition;
                }
            }
        }

        $updates = [];
        $providedPlayerIds = [];
        foreach ($positions as $orderItem) {
            $playerId = $orderItem['playerId'] ?? null;
            $position = $orderItem['position'] ?? null;
            if (!is_int($playerId) || !is_int($position)) {
                continue;
            }

            if (!isset($playersById[$playerId])) {
                throw new InvalidPlayerOrderException(sprintf('Player %d is not part of the game.', $playerId));
            }

            $updates[$playerId] = $position;
            $providedPlayerIds[$playerId] = true;
            $finalPositionsByPlayerId[$playerId] = $position;
        }

        if (count($updates) !== count($playersById)) {
            throw new InvalidPlayerOrderException('Player order update must include all players in the game.');
        }

        foreach ($playersById as $playerId => $_gamePlayer) {
            if (!isset($providedPlayerIds[$playerId])) {
                throw new InvalidPlayerOrderException(sprintf('Missing player %d in positions payload.', $playerId));
            }
        }

        $providedPositions = array_values($updates);
        sort($providedPositions);
        if ([] !== $providedPositions) {
            $minPosition = $providedPositions[0];
            if (0 !== $minPosition && 1 !== $minPosition) {
                throw new InvalidPlayerOrderException('Positions must start with 0 or 1 and be contiguous.');
            }

            $expectedPositions = range($minPosition, $minPosition + count($providedPositions) - 1);
            if ($providedPositions !== $expectedPositions) {
                throw new InvalidPlayerOrderException('Positions must be contiguous without gaps.');
            }
        }

        $seenFinalPositions = [];
        foreach ($finalPositionsByPlayerId as $playerId => $position) {
            if (isset($seenFinalPositions[$position])) {
                $conflictingPlayerId = $seenFinalPositions[$position];
                throw new InvalidPlayerOrderException(sprintf(
                    'Resulting positions contain duplicate position %d for playerId %d and playerId %d.',
                    $position,
                    $conflictingPlayerId,
                    $playerId
                ));
            }

            $seenFinalPositions[$position] = $playerId;
        }

        foreach ($updates as $playerId => $position) {
            $playersById[$playerId]->setPosition($position);
        }

        $this->entityManager->flush();
    }

    /**
     * @param int|null $position
     * @param int      $gameId
     *
     * @return int
     */
    private function resolvePosition(?int $position, int $gameId): int
    {
        if (is_int($position) && $position >= 0) {
            return $position;
        }

        $gamePlayers = $this->gamePlayersRepository->findBy(['game' => $gameId]);
        $maxPosition = null;
        foreach ($gamePlayers as $existingPlayer) {
            $existingPosition = $existingPlayer->getPosition();
            if (null !== $existingPosition && $existingPosition >= 0) {
                $maxPosition = null === $maxPosition
                    ? $existingPosition
                    : max($maxPosition, $existingPosition);
            }
        }

        if (null === $maxPosition) {
            return count($gamePlayers) + 1;
        }

        return $maxPosition + 1;
    }

    /**
     * @param User|null $player
     * @param int       $playerId
     *
     * @return string
     */
    private function resolveDisplayNameSnapshot(?User $player, int $playerId): string
    {
        if (null === $player) {
            return sprintf('player_%d', $playerId);
        }

        $displayName = $player->getDisplayNameRaw() ?? $player->getUsername();

        return null !== $displayName && '' !== $displayName
            ? $displayName
            : sprintf('player_%d', $playerId);
    }
}
