<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Invitation;
use App\Enum\GameStatus;
use App\Repository\InvitationRepositoryInterface;
use App\Service\Game\GameRoomServiceInterface;
use App\Service\Game\GameFinishServiceInterface;
use App\Service\Player\PlayerManagementServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;
use Override;

/**
 * Service to handle rematches.
 * This class is responsible for creating a new game and copying players from the old game.
 *
 * @psalm-suppress UnusedClass Reason: service is auto-wired and used via interface.
 */
final readonly class RematchService implements RematchServiceInterface
{
    /**
     * @param GameRoomServiceInterface         $gameRoomService
     * @param PlayerManagementServiceInterface $playerManagementService
     * @param GameFinishServiceInterface       $gameFinishService
     * @param InvitationRepositoryInterface    $invitationRepository
     * @param EntityManagerInterface           $entityManager
     * @param UrlGeneratorInterface            $urlGenerator
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function __construct(
        private GameRoomServiceInterface $gameRoomService,
        private PlayerManagementServiceInterface $playerManagementService,
        private GameFinishServiceInterface $gameFinishService,
        private InvitationRepositoryInterface $invitationRepository,
        private EntityManagerInterface $entityManager,
        private UrlGeneratorInterface $urlGenerator
    ) {
    }

    /**
     * @param int $oldGameId
     *
     * @throws ORMException
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function createRematch(int $oldGameId): array
    {
        $oldGame = $this->gameRoomService->findGameById($oldGameId);
        if (!$oldGame) {
            return ['success' => false, 'message' => 'Previous game not found'];
        }

        $newGame = $this->gameRoomService->createGame();
        $newGame->setStatus(GameStatus::Lobby);
        $newGame->setRound(null);
        $newGame->setStartScore($oldGame->getStartScore());
        $newGame->setDoubleOut($oldGame->isDoubleOut());
        $newGame->setTripleOut($oldGame->isTripleOut());
        if (null !== $oldGame->getType()) {
            $newGame->setType((int) $oldGame->getType());
        }
        $newGameId = $newGame->getGameId();
        if (null === $newGameId) {
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
