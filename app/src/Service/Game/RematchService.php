<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Invitation;
use App\Repository\InvitationRepositoryInterface;
use App\Service\Game\GameRoomServiceInterface;
use App\Service\Player\PlayerManagementServiceInterface;
use App\Service\Security\GameAccessServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;
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
     * @param GameRoomServiceInterface         $gameRoomService
     * @param PlayerManagementServiceInterface $playerManagementService
     * @param InvitationRepositoryInterface    $invitationRepository
     * @param EntityManagerInterface           $entityManager
     * @param UrlGeneratorInterface            $urlGenerator
     * @param GameAccessServiceInterface       $gameAccessService
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function __construct(private GameRoomServiceInterface $gameRoomService, private PlayerManagementServiceInterface $playerManagementService, private InvitationRepositoryInterface $invitationRepository, private EntityManagerInterface $entityManager, private UrlGeneratorInterface $urlGenerator, private GameAccessServiceInterface $gameAccessService)
    {
    }

    /**
     * @param int $oldGameId
     *
     * @throws ORMException
     *
      * @return array{success: bool, gameId?: int, invitationLink?: string, message?: string}
     */
    #[Override]
    public function createRematch(int $oldGameId): array
    {
        $oldGame = $this->gameRoomService->findGameById($oldGameId);
        if (!$oldGame) {
            return ['success' => false, 'message' => 'Previous game not found'];
        }
        $this->gameAccessService->assertPlayerInGameOrAdmin($oldGame);

        $lobby = $this->gameRoomService->openLobby();
        $lobbyGame = $lobby->game;
        $lobbyGame->setStartScore($oldGame->getStartScore());
        $lobbyGame->setDoubleOut($oldGame->isDoubleOut());
        $lobbyGame->setTripleOut($oldGame->isTripleOut());
        if (null !== $oldGame->getType()) {
            $lobbyGame->setType((int) $oldGame->getType());
        }
        $this->entityManager->flush();
        $lobbyGameId = $lobbyGame->getGameId();
        if (null === $lobbyGameId) {
            return ['success' => false, 'message' => 'Failed to open the lobby'];
        }

        if ($lobby->created) {
            $this->playerManagementService->copyPlayers($oldGameId, $lobbyGameId);
        } else {
            // Players who joined the open lobby keep their seats.
            $this->playerManagementService->appendMissingPlayers($oldGameId, $lobbyGameId);
        }
        $invitationLink = $this->createInvitation($lobbyGameId);

        return [
            'success' => true,
            'gameId' => $lobbyGameId,
            'invitationLink' => $invitationLink,
        ];
    }

    /**
     * @param int $gameId
     *
     * @return string
     */
    private function createInvitation(int $gameId): string
    {
        $invitation = $this->invitationRepository->findOneBy(['gameId' => $gameId]);
        if (null === $invitation) {
            $uuid = Uuid::v4();
            $invitation = new Invitation();
            $invitation->setUuid($uuid);
            $invitation->setGameId($gameId);
            $this->entityManager->persist($invitation);
            $this->entityManager->flush();
        }

        return $this->urlGenerator->generate('join_invitation', ['uuid' => $invitation->getUuid()]);
    }
}
