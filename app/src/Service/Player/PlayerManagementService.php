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
use App\Service\Game\ParticipantResult;
use App\Service\Game\PlayerDisplayName;
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
        /** @var User $player */
        $player = $this->entityManager->getReference(User::class, $playerId);

        return $this->addPlayerEntity($gameId, $player, $position);
    }

    /**
     * @param int      $gameId
     * @param User     $player
     * @param int|null $position
     * @param bool     $flush
     *
     * @throws ORMException
     *
     * @return GamePlayers
     */
    #[Override]
    public function addPlayerEntity(int $gameId, User $player, ?int $position = null, bool $flush = true): GamePlayers
    {
        $gamePlayer = $this->createGamePlayer($gameId, $player, $position);
        if (true === $flush) {
            $this->entityManager->flush();
        }

        return $gamePlayer;
    }

    /**
     * Copy every player from one game to another.
     *
     * @param int  $fromGameId
     * @param int  $toGameId
     * @param bool $reverseByPlacement Seat the last placed first and number the seats 1, 2, 3
     *
     * @throws ORMException
     *
     * @return void
     */
    #[Override]
    public function copyPlayers(int $fromGameId, int $toGameId, bool $reverseByPlacement = false): void
    {
        $oldGamePlayers = $this->seatsToCopy($fromGameId, $reverseByPlacement);
        $orderIndex = 0;
        $hasCopiedPlayers = false;
        foreach ($oldGamePlayers as $oldGamePlayer) {
            $player = $oldGamePlayer->getPlayer();
            if (null === $player) {
                continue;
            }

            if (null !== $player->getId()) {
                $position = $reverseByPlacement ? null : $oldGamePlayer->getPosition();
                if (!is_int($position)) {
                    $orderIndex++;
                    $position = $orderIndex;
                } else {
                    $orderIndex = max($orderIndex, $position);
                }

                $this->createGamePlayer($toGameId, $player, $position);
                $hasCopiedPlayers = true;
            }
        }

        if (true === $hasCopiedPlayers) {
            $this->entityManager->flush();
        }
    }

    /**
     * Seat every player of one game who is missing from another, after the players already there.
     *
     * @param int  $fromGameId
     * @param int  $toGameId
     * @param bool $reverseByPlacement Append the last placed first
     *
     * @throws ORMException
     *
     * @return void
     */
    #[Override]
    public function appendMissingPlayers(int $fromGameId, int $toGameId, bool $reverseByPlacement = false): void
    {
        $seatedPlayerIds = [];
        $lastPosition = 0;
        // A plain read inside the caller's transaction could miss seats another request
        // committed after the snapshot, and seat those players twice.
        $seats = $this->gamePlayersRepository->findByGameIdForShare($toGameId);
        $seatCount = count($seats);
        foreach ($seats as $seat) {
            $playerId = $seat->getPlayer()?->getId();
            if (null !== $playerId) {
                $seatedPlayerIds[$playerId] = true;
            }
            $lastPosition = max($lastPosition, $seat->getPosition() ?? 0);
        }

        $hasAddedPlayers = false;
        foreach ($this->seatsToCopy($fromGameId, $reverseByPlacement) as $oldSeat) {
            if ($seatCount >= Game::MAX_PARTICIPANTS) {
                break;
            }
            $player = $oldSeat->getPlayer();
            $playerId = $player?->getId();
            if (null === $player || null === $playerId || isset($seatedPlayerIds[$playerId])) {
                continue;
            }

            $lastPosition++;
            $this->createGamePlayer($toGameId, $player, $lastPosition);
            $seatedPlayerIds[$playerId] = true;
            $seatCount++;
            $hasAddedPlayers = true;
        }

        if (true === $hasAddedPlayers) {
            $this->entityManager->flush();
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

        return $this->gamePlayersRepository->findNextPositionForGame($gameId);
    }

    /**
     * @param int      $gameId
     * @param User     $player
     * @param int|null $position
     *
     * @throws ORMException
     *
     * @return GamePlayers
     */
    private function createGamePlayer(int $gameId, User $player, ?int $position = null): GamePlayers
    {
        $gamePlayer = new GamePlayers();
        // getReference() returns the managed Game when it is already loaded, so add the
        // seat through the inverse side too; otherwise getGamePlayers() misses it until reload.
        // The first add loads the game's seats (and the game, if still a proxy); later adds reuse them.
        /** @var Game $game */
        $game = $this->entityManager->getReference(Game::class, $gameId);
        $game->addGamePlayer($gamePlayer);
        $gamePlayer->setPlayer($player);
        $gamePlayer->setDisplayNameSnapshot(PlayerDisplayName::forUser($player));
        $gamePlayer->setPosition($this->resolvePosition($position, $gameId));
        $this->entityManager->persist($gamePlayer);

        return $gamePlayer;
    }

    /**
     * The seats of the old game in the order a rematch takes them: the seat order, or for a finished
     * game the reverse placement order, ranked as the game summary ranks them.
     *
     * @param int  $fromGameId
     * @param bool $reverseByPlacement
     *
     * @return list<GamePlayers>
     */
    private function seatsToCopy(int $fromGameId, bool $reverseByPlacement): array
    {
        $seats = array_values($this->gamePlayersRepository->findByGameId($fromGameId));
        if ($reverseByPlacement) {
            usort($seats, ParticipantResult::compareByPlacement(...));
            $seats = array_reverse($seats);
        }

        return $seats;
    }
}
