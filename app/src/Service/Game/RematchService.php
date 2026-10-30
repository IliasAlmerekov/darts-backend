<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Game;

use App\Dto\RematchResponseDto;
use App\Entity\Game;
use App\Enum\GameStatus;
use App\Repository\GameRepositoryInterface;
use App\Service\Invitation\InvitationServiceInterface;
use App\Service\Player\PlayerManagementServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use LogicException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Override;

/**
 * Service to handle rematches.
 * Returns the open lobby, or a new one, with the old game's players and settings (ADR 0003).
 *
 * @psalm-suppress UnusedClass Reason: service is auto-wired and used via interface.
 */
final readonly class RematchService implements RematchServiceInterface
{
    /**
     * @param PlayerManagementServiceInterface $playerManagementService
     * @param InvitationServiceInterface       $invitationService
     * @param EntityManagerInterface           $entityManager
     * @param UrlGeneratorInterface            $urlGenerator
     * @param OpenLobbyServiceInterface        $openLobbyService
     * @param GameRepositoryInterface          $gameRepository
     * @param LockedGameTransactionInterface   $lockedGameTransaction
     *
     */
    public function __construct(private PlayerManagementServiceInterface $playerManagementService, private InvitationServiceInterface $invitationService, private EntityManagerInterface $entityManager, private UrlGeneratorInterface $urlGenerator, private OpenLobbyServiceInterface $openLobbyService, private GameRepositoryInterface $gameRepository, private LockedGameTransactionInterface $lockedGameTransaction)
    {
    }

    /**
     * @param int $oldGameId
     *
     * @throws ORMException
     *
     * @return RematchResponseDto
     */
    #[Override]
    public function createRematch(int $oldGameId): RematchResponseDto
    {
        // Loads the status under the game's lock, so a parallel reopen of this game either
        // commits first and is seen, or waits until the rematch ends.
        return $this->lockedGameTransaction->runWithRetry($oldGameId, function (Game $oldGame) use ($oldGameId): RematchResponseDto {
            // Aborted too: the exit flow aborts the started game, then asks for its rematch.
            NewestGameRule::assert($oldGame, [GameStatus::Finished, GameStatus::Aborted], $this->gameRepository);

            $lobby = $this->openLobbyService->findOrCreate();
            $lobbyGame = $lobby->game;
            $lobbyGame->setStartScore($oldGame->getStartScore());
            $lobbyGame->setOutRule($oldGame->outRule());
            $this->entityManager->flush();
            $lobbyGameId = $lobbyGame->getGameId() ?? throw new LogicException('The lobby game has no id.');

            // After a finished game the last placed participant throws first; an aborted game has no placements.
            $lastPlacedFirst = GameStatus::Finished === $oldGame->getStatus();
            if ($lobby->created) {
                $this->playerManagementService->copyPlayers($oldGameId, $lobbyGameId, $lastPlacedFirst);
            } else {
                // Players who joined the open lobby keep their seats.
                $this->playerManagementService->appendMissingPlayers($oldGameId, $lobbyGameId, $lastPlacedFirst);
            }

            $invitation = $this->invitationService->createOrGetInvitation($lobbyGame);
            $url = $this->urlGenerator->generate('join_invitation', ['uuid' => $invitation->getUuid()]);

            return new RematchResponseDto($lobbyGameId, $url);
        });
    }
}
