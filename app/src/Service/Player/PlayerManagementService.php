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
use App\Exception\Game\GameNotFoundException;
use App\Exception\Game\GameRoomFullException;
use App\Exception\Game\InvalidPlayerOrderException;
use App\Exception\Game\PlayerNotFoundException;
use App\Exception\Game\SeatsNotEditableException;
use App\Repository\GamePlayersRepositoryInterface;
use App\Repository\GameRepositoryInterface;
use App\Repository\UserRepositoryInterface;
use App\Service\Game\ParticipantResult;
use App\Service\Game\PlayerDisplayName;
use Closure;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Override;

/**
 * The seat module: every seat write of a game goes through here.
 *
 * Each write runs in the caller's transaction, or in its own when there is none, and starts with one
 * locking read of the game and its seats. Under that lock it checks that the game is in the
 * lobby and below Game::MAX_PARTICIPANTS, gives a new seat the next position and the display
 * name snapshot, and flushes.
 *
 * @psalm-suppress UnusedClass Reason: service is auto-wired and used via interface.
 */
final readonly class PlayerManagementService implements PlayerManagementServiceInterface
{
    /**
     * @param GamePlayersRepositoryInterface $gamePlayersRepository
     * @param EntityManagerInterface         $entityManager
     * @param GameRepositoryInterface        $gameRepository
     * @param UserRepositoryInterface        $userRepository
     */
    public function __construct(
        private GamePlayersRepositoryInterface $gamePlayersRepository,
        private EntityManagerInterface $entityManager,
        private GameRepositoryInterface $gameRepository,
        private UserRepositoryInterface $userRepository,
    ) {
    }

    /**
     * @param int $gameId
     * @param int $playerId
     *
     * @throws GameNotFoundException     When no game has this ID
     * @throws SeatsNotEditableException When the game is no longer in the lobby
     *
     * @return bool
     */
    #[Override]
    public function removePlayer(int $gameId, int $playerId): bool
    {
        return $this->inSeatTransaction(function () use ($gameId, $playerId): bool {
            $this->lockEditableSeats($gameId);

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
        });
    }

    /**
     * @param int $gameId
     * @param int $playerId
     *
     * @throws GameNotFoundException     When no game has this ID
     * @throws SeatsNotEditableException When the player has no seat and the game is no longer in the lobby
     * @throws GameRoomFullException     When the player has no seat and the game is full
     * @throws ORMException
     *
     * @return void
     */
    #[Override]
    public function addPlayer(int $gameId, int $playerId): void
    {
        $this->inSeatTransaction(function () use ($gameId, $playerId): void {
            $game = $this->lockGame($gameId);
            foreach ($game->getGamePlayers() as $seat) {
                if ($playerId === $seat->getPlayer()?->getId()) {
                    return;
                }
            }

            $this->assertSeatsEditable($game);
            $this->assertSeatsFree($game, 1);
            /** @var User $player */
            $player = $this->entityManager->getReference(User::class, $playerId);
            $this->createGamePlayer($game, $player);
        });
    }

    /**
     * @param int             $gameId
     * @param Closure(): User $createPlayer
     *
     * @throws GameNotFoundException     When no game has this ID
     * @throws SeatsNotEditableException When the game is no longer in the lobby
     * @throws GameRoomFullException     When the game is full
     *
     * @return GamePlayers
     */
    #[Override]
    public function addNewPlayer(int $gameId, Closure $createPlayer): GamePlayers
    {
        return $this->inSeatTransaction(function () use ($gameId, $createPlayer): GamePlayers {
            $game = $this->lockEditableSeats($gameId);
            $this->assertSeatsFree($game, 1);
            $player = $createPlayer();
            $this->entityManager->persist($player);

            return $this->createGamePlayer($game, $player);
        });
    }

    /**
     * @param int       $gameId
     * @param list<int> $playerIds
     *
     * @throws GameNotFoundException     When no game has this ID
     * @throws SeatsNotEditableException When the game is no longer in the lobby
     * @throws GameRoomFullException     When the players do not fit into the free seats
     * @throws PlayerNotFoundException   When an id belongs to no user
     *
     * @return void
     */
    #[Override]
    public function addPlayers(int $gameId, array $playerIds): void
    {
        $this->inSeatTransaction(function () use ($gameId, $playerIds): void {
            $game = $this->lockEditableSeats($gameId);
            $this->assertSeatsFree($game, count($playerIds));
            if ([] === $playerIds) {
                return;
            }

            $playersById = [];
            foreach ($this->userRepository->findByIds($playerIds) as $player) {
                $playersById[(int) $player->getId()] = $player;
            }
            // Every id resolves before the first seat, so an unknown id seats nobody.
            $players = array_map(static fn (int $playerId): User => $playersById[$playerId] ?? throw new PlayerNotFoundException(), $playerIds);
            foreach ($players as $player) {
                $this->createGamePlayer($game, $player);
            }
        });
    }

    /**
     * Copy every player from one game to another.
     *
     * @param int  $fromGameId
     * @param int  $toGameId
     * @param bool $reverseByPlacement Seat the last placed first and number the seats 1, 2, 3
     *
     * @throws GameNotFoundException     When the target game does not exist
     * @throws SeatsNotEditableException When the target game is no longer in the lobby
     * @throws ORMException
     *
     * @return void
     */
    #[Override]
    public function copyPlayers(int $fromGameId, int $toGameId, bool $reverseByPlacement = false): void
    {
        $this->inSeatTransaction(function () use ($fromGameId, $toGameId, $reverseByPlacement): void {
            $game = $this->lockEditableSeats($toGameId);
            $orderIndex = 0;
            foreach ($this->seatsToCopy($fromGameId, $reverseByPlacement) as $oldGamePlayer) {
                if (!$this->hasFreeSeat($game)) {
                    break;
                }
                $player = $oldGamePlayer->getPlayer();
                if (null === $player || null === $player->getId()) {
                    continue;
                }

                $position = $reverseByPlacement ? null : $oldGamePlayer->getPosition();
                if (!is_int($position)) {
                    $orderIndex++;
                    $position = $orderIndex;
                } else {
                    $orderIndex = max($orderIndex, $position);
                }

                $this->createGamePlayer($game, $player, $position);
            }
        });
    }

    /**
     * Seat every player of one game who is missing from another, after the players already there.
     *
     * @param int  $fromGameId
     * @param int  $toGameId
     * @param bool $reverseByPlacement Append the last placed first
     *
     * @throws GameNotFoundException     When the target game does not exist
     * @throws SeatsNotEditableException When the target game is no longer in the lobby
     * @throws ORMException
     *
     * @return void
     */
    #[Override]
    public function appendMissingPlayers(int $fromGameId, int $toGameId, bool $reverseByPlacement = false): void
    {
        $this->inSeatTransaction(function () use ($fromGameId, $toGameId, $reverseByPlacement): void {
            $game = $this->lockEditableSeats($toGameId);
            $seatedPlayerIds = [];
            foreach ($game->getGamePlayers() as $seat) {
                $playerId = $seat->getPlayer()?->getId();
                if (null !== $playerId) {
                    $seatedPlayerIds[$playerId] = true;
                }
            }

            foreach ($this->seatsToCopy($fromGameId, $reverseByPlacement) as $oldSeat) {
                if (!$this->hasFreeSeat($game)) {
                    break;
                }
                $player = $oldSeat->getPlayer();
                $playerId = $player?->getId();
                if (null === $player || null === $playerId || isset($seatedPlayerIds[$playerId])) {
                    continue;
                }

                $this->createGamePlayer($game, $player);
                $seatedPlayerIds[$playerId] = true;
            }
        });
    }

    /**
     * @param int                                           $gameId
     * @param list<array{playerId:int, position:int}>|array $positions
     *
     * @throws GameNotFoundException       When no game has this ID
     * @throws SeatsNotEditableException   When the game is no longer in the lobby
     * @throws InvalidPlayerOrderException When the positions do not cover every seat exactly once
     *
     * @return void
     */
    #[Override]
    public function updatePlayerPositions(int $gameId, array $positions): void
    {
        $this->inSeatTransaction(function () use ($gameId, $positions): void {
            $this->lockEditableSeats($gameId);
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
        });
    }

    /**
     * Runs a seat write in the caller's transaction when there is one, else in its own.
     *
     * A nested wrapInTransaction() would set a savepoint. A deadlock rolls back the whole MySQL
     * transaction, savepoint included, so its ROLLBACK TO SAVEPOINT would fail and hide the
     * RetryableException from RetryingTransaction (ADR 0003).
     *
     * @template T
     *
     * @param Closure(): T $write
     *
     * @return T
     */
    private function inSeatTransaction(Closure $write): mixed
    {
        if (!$this->entityManager->getConnection()->isTransactionActive()) {
            return $this->entityManager->wrapInTransaction($write);
        }

        $result = $write();
        $this->entityManager->flush();

        return $result;
    }

    /**
     * The one locking read of every seat write. It reloads a game this request already holds,
     * so the status and the seats show what other transactions committed before the lock.
     *
     * @param int $gameId
     *
     * @throws GameNotFoundException When no game has this ID
     *
     * @return Game
     */
    private function lockGame(int $gameId): Game
    {
        return $this->gameRepository->findOneByGameIdWithSeatsForUpdate($gameId) ?? throw new GameNotFoundException();
    }

    /**
     * @param int $gameId
     *
     * @throws GameNotFoundException     When no game has this ID
     * @throws SeatsNotEditableException When the game is no longer in the lobby
     *
     * @return Game
     */
    private function lockEditableSeats(int $gameId): Game
    {
        $game = $this->lockGame($gameId);
        $this->assertSeatsEditable($game);

        return $game;
    }

    /**
     * @param Game $game
     *
     * @throws SeatsNotEditableException When the game is no longer in the lobby
     *
     * @return void
     */
    private function assertSeatsEditable(Game $game): void
    {
        if (!$game->getStatus()->canChangeSeats()) {
            throw new SeatsNotEditableException($game->getStatus());
        }
    }

    /**
     * @param Game $game
     * @param int  $seats
     *
     * @throws GameRoomFullException When the game has fewer free seats
     *
     * @return void
     */
    private function assertSeatsFree(Game $game, int $seats): void
    {
        if ($game->getGamePlayers()->count() + $seats > Game::MAX_PARTICIPANTS) {
            throw new GameRoomFullException(Game::MAX_PARTICIPANTS);
        }
    }

    /**
     * @param Game $game
     *
     * @return bool
     */
    private function hasFreeSeat(Game $game): bool
    {
        return $game->getGamePlayers()->count() < Game::MAX_PARTICIPANTS;
    }

    /**
     * The position after the highest one in the locked seats, or after the seat count when no seat has one.
     *
     * @param Game $game
     *
     * @return int
     */
    private function nextPosition(Game $game): int
    {
        $maxPosition = null;
        foreach ($game->getGamePlayers() as $seat) {
            $position = $seat->getPosition();
            if (null !== $position) {
                $maxPosition = max($maxPosition ?? $position, $position);
            }
        }

        return null !== $maxPosition ? $maxPosition + 1 : $game->getGamePlayers()->count() + 1;
    }

    /**
     * @param Game     $game     The game locked by lockGame()
     * @param User     $player
     * @param int|null $position
     *
     * @return GamePlayers
     */
    private function createGamePlayer(Game $game, User $player, ?int $position = null): GamePlayers
    {
        $gamePlayer = new GamePlayers();
        $gamePlayer->setPlayer($player);
        $gamePlayer->setDisplayNameSnapshot(PlayerDisplayName::forUser($player));
        $gamePlayer->setPosition($position ?? $this->nextPosition($game));
        $game->addGamePlayer($gamePlayer);
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
