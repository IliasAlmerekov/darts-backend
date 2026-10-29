<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Security;

use App\Dto\LoginSuccessDto;
use App\Entity\User;
use App\Exception\Game\GameAlreadyStartedException;
use App\Exception\Game\GameJoinNotAllowedException;
use App\Exception\Game\GameNotFoundException;
use App\Exception\Game\GameRoomFullException;
use App\Repository\GamePlayersRepositoryInterface;
use App\Service\Invitation\InvitationServiceInterface;
use Override;

/**
 * Decides the outcome of a successful login.
 */
final readonly class SecurityService implements SecurityServiceInterface
{
    /**
     * @param InvitationServiceInterface     $invitationService
     * @param GamePlayersRepositoryInterface $gamePlayersRepository
     */
    public function __construct(
        private InvitationServiceInterface $invitationService,
        private GamePlayersRepositoryInterface $gamePlayersRepository,
    ) {
    }

    /**
     * Admin goes to the start page, an invited player joins the game, any other player
     * returns to their current game or lands on the profile.
     *
     * @param User     $user
     * @param int|null $invitedGameId
     *
     * @return LoginSuccessDto
     */
    #[Override]
    public function resolveLoginSuccess(User $user, ?int $invitedGameId): LoginSuccessDto
    {
        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return new LoginSuccessDto($user, '/start');
        }

        if (null !== $invitedGameId) {
            try {
                return new LoginSuccessDto($user, '/joined', gameId: $this->invitationService->joinInvitedGame($invitedGameId, $user));
            } catch (GameAlreadyStartedException|GameJoinNotAllowedException|GameRoomFullException|GameNotFoundException $exception) {
                // The login itself succeeded; report why the join did not instead of failing the request.
                return new LoginSuccessDto($user, '/playerprofile', invitationError: $exception->getErrorCode());
            }
        }

        // Without an invitation a player returns to their current game, if they have one.
        $playerId = $user->getId();
        $currentGame = null !== $playerId ? $this->gamePlayersRepository->findCurrentGameForPlayer($playerId) : null;
        if (null !== $currentGame) {
            return new LoginSuccessDto($user, '/joined', gameId: $currentGame['gameId']);
        }

        return new LoginSuccessDto($user, '/playerprofile');
    }
}
