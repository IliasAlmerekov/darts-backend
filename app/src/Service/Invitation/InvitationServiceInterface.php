<?php

declare(strict_types=1);

namespace App\Service\Invitation;

use App\Entity\Game;
use App\Entity\Invitation;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

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
     * @return array<string, mixed>
     */
    public function getInvitationPayload(Game $game): array;

    /**
     * Ensures that the game can be joined (lobby state).
     *
     * @param int $gameId
     *
     * @return void
     */
    public function assertGameJoinable(int $gameId): void;

    /**
     * Processes an invitation join flow for the current user from session.
     *
     * @param SessionInterface $session
     * @param object|null      $user
     *
     * @return Response
     */
    public function processInvitation(SessionInterface $session, mixed $user): Response;

    /**
     * Returns users participating in the given game.
     *
     * @param Game $game
     *
     * @return array<int, array{id:int|null,username:string|null}>
     */
    public function getUsersForGame(Game $game): array;
}
