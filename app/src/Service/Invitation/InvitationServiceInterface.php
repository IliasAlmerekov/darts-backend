<?php

declare(strict_types=1);

namespace App\Service\Invitation;

use App\Dto\InvitationResponseDto;
use App\Entity\Game;
use App\Entity\Invitation;
use App\Entity\User;
use App\Exception\Game\GameAlreadyStartedException;
use App\Exception\Game\GameJoinNotAllowedException;
use App\Exception\Game\GameNotFoundException;
use App\Exception\Game\GameRoomFullException;

/**
 * Contract for invitation related operations.
 */
interface InvitationServiceInterface
{
    /**
     * Finds existing or creates a new invitation for the given game.
     *
     * @param Game $game
     *
     * @return Invitation
     */
    public function createOrGetInvitation(Game $game): Invitation;

    /**
     * Builds full invitation payload for API responses (link, users, ids).
     *
     * @param Game $game
     *
     * @return InvitationResponseDto
     *
     * @throws GameNotFoundException when the game has no id
     */
    public function getInvitationPayload(Game $game): InvitationResponseDto;

    /**
     * Seats the player in the invited game, unless they already have a seat there.
     *
     * @param int  $gameId
     * @param User $user
     *
     * @return int the joined game's id
     *
     * @throws GameNotFoundException        when the game no longer exists
     * @throws GameAlreadyStartedException  when the game has started
     * @throws GameJoinNotAllowedException  when the game has ended
     * @throws GameRoomFullException        when every seat is taken
     */
    public function joinInvitedGame(int $gameId, User $user): int;

    /**
     * Returns users participating in the given game.
     *
     * @param Game $game
     *
     * @return array<int, array{id:int|null,username:string|null}>
     */
    public function getUsersForGame(Game $game): array;
}
