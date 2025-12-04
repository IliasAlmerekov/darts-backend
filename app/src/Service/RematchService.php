<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Invitation;
use App\Enum\GameStatus;
use App\Repository\InvitationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Service to handle rematches.
 * This class is responsible for creating a new game and copying players from the old game.
 */
final readonly class RematchService
{
    public function __construct(
        private GameRoomService $gameRoomService,
        private PlayerManagementService $playerManagementService,
        private GameFinishService $gameFinishService,
        private InvitationRepository $invitationRepository,
        private EntityManagerInterface $entityManager,
        private UrlGeneratorInterface $urlGenerator
    ) {
    }

    /**
     * @throws ORMException
     */
    public function createRematch(int $oldGameId): array
    {
        $oldGame = $this->gameRoomService->findGameById($oldGameId);
        if (!$oldGame) {
            return ['success' => false, 'message' => 'Previous game not found'];
        }

        $newGame = $this->gameRoomService->createGame();
        $newGame->setStatus(GameStatus::Lobby);
        $newGame->setRound(null);
        $newGameId = $newGame->getGameId();
        if ($newGameId === null) {
            return ['success' => false, 'message' => 'Failed to create new game'];
        }

        $this->playerManagementService->copyPlayers($oldGameId, $newGameId);
        $invitationLink = $this->createInvitation($newGameId);
        $finishedPlayers = $this->gameFinishService->finishGame($oldGame);
        return [
            'success' => true,
            'gameId' => $newGameId,
            'invitationLink' => $invitationLink,
            'finishedPlayers' => $finishedPlayers,
        ];
    }

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
