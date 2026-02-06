<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Invitation;

use App\Entity\Game;
use App\Entity\Invitation;
use App\Entity\User;
use App\Enum\GameStatus;
use App\Exception\Game\GameJoinNotAllowedException;
use App\Exception\Game\GameNotFoundException;
use App\Repository\GamePlayersRepositoryInterface;
use App\Repository\GameRepositoryInterface;
use App\Repository\InvitationRepositoryInterface;
use App\Repository\UserRepositoryInterface;
use App\Service\Player\PlayerManagementServiceInterface;
use App\Service\Security\GameAccessServiceInterface;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Override;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Service to handle invitation creation and related data.
 *
 * @psalm-suppress UnusedClass Reason: service is wired and used via DI.
 */
final readonly class InvitationService implements InvitationServiceInterface
{
    /**
     * @param InvitationRepositoryInterface    $invitationRepository
     * @param GamePlayersRepositoryInterface   $gamePlayersRepository
     * @param GameRepositoryInterface          $gameRepository
     * @param UserRepositoryInterface          $userRepository
     * @param PlayerManagementServiceInterface $playerManagementService
     * @param EntityManagerInterface           $entityManager
     * @param RouterInterface                  $router
     * @param GameAccessServiceInterface       $gameAccessService
     */
    public function __construct(
        private InvitationRepositoryInterface $invitationRepository,
        private GamePlayersRepositoryInterface $gamePlayersRepository,
        private GameRepositoryInterface $gameRepository,
        private UserRepositoryInterface $userRepository,
        private PlayerManagementServiceInterface $playerManagementService,
        private EntityManagerInterface $entityManager,
        private RouterInterface $router,
        private GameAccessServiceInterface $gameAccessService,
    ) {
    }

    /**
     * @param Game $game
     *
     * @return Invitation
     */
    #[Override]
    public function createOrGetInvitation(Game $game): Invitation
    {
        $gameId = $game->getGameId();
        if (null === $gameId) {
            throw new GameNotFoundException();
        }

        return $this->entityManager->wrapInTransaction(function () use ($game, $gameId): Invitation {
            if ($this->entityManager->contains($game)) {
                $this->entityManager->lock($game, LockMode::PESSIMISTIC_WRITE);
            }

            $candidate = $this->invitationRepository->findOneBy(['gameId' => $gameId]);
            if ($candidate instanceof Invitation) {
                return $candidate;
            }

            $invitation = new Invitation();
            $invitation->setUuid(Uuid::v4());
            $invitation->setGameId($gameId);
            $this->entityManager->persist($invitation);
            $this->entityManager->flush();

            return $invitation;
        });
    }

    /**
     * @param Game $game
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function getInvitationPayload(Game $game): array
    {
        $this->gameAccessService->assertPlayerInGameOrAdmin($game);
        $gameId = $game->getGameId();
        if (null === $gameId) {
            return ['success' => false, 'message' => 'Game not found'];
        }

        $invitation = $this->createOrGetInvitation($game);
        $users = $this->getUsersForGame($game);
        $invitationLink = $this->router->generate('join_invitation', ['uuid' => $invitation->getUuid()]);

        return [
            'success' => true,
            'gameId' => $gameId,
            'invitationLink' => $invitationLink,
            'users' => $users,
        ];
    }

    /**
     * @param int $gameId
     *
     * @return void
     */
    #[Override]
    public function assertGameJoinable(int $gameId): void
    {
        $game = $this->gameRepository->find($gameId);
        if (!$game instanceof Game) {
            throw new GameNotFoundException();
        }

        $status = $game->getStatus();
        if (GameStatus::Lobby !== $status) {
            throw new GameJoinNotAllowedException($status);
        }
    }

    /**
     * @param Game $game
     *
     * @return array<int, array{id:int|null,username:string|null}>
     */
    #[Override]
    public function getUsersForGame(Game $game): array
    {
        $this->gameAccessService->assertPlayerInGameOrAdmin($game);
        $gameId = $game->getGameId();
        if (null === $gameId) {
            return [];
        }

        $players = $this->gamePlayersRepository->findByGameId($gameId);
        $playerIds = array_values(array_filter(array_map(
            static fn($player) => $player->getPlayer()?->getId(),
            $players
        )));

        if ([] === $playerIds) {
            return [];
        }

        /** @var User[] $users */
        $users = $this->userRepository->findBy(['id' => $playerIds]);

        return array_map(static function (User $user): array {
            return [
                'id' => $user->getId(),
                'username' => $user->getDisplayName(),
            ];
        }, $users);
    }

    /**
     * @param SessionInterface $session
     * @param mixed            $user
     *
     * @return Response
     */
    #[Override]
    public function processInvitation(SessionInterface $session, mixed $user): Response
    {
        if (!$user instanceof User) {
            return new JsonResponse(['success' => false, 'redirect' => '/login'], Response::HTTP_UNAUTHORIZED);
        }

        $gameId = $session->get('game_id');
        if (!$gameId) {
            return new JsonResponse(['success' => false, 'redirect' => '/start'], Response::HTTP_BAD_REQUEST);
        }
        $gameId = (int) $gameId;
        $this->assertGameJoinable($gameId);

        $userId = $user->getId();
        if (null === $userId) {
            return new JsonResponse(['success' => false, 'redirect' => '/login'], Response::HTTP_UNAUTHORIZED);
        }

        if (!$this->gamePlayersRepository->isPlayerInGame($gameId, $userId)) {
            $this->playerManagementService->addPlayer($gameId, $userId);
        }

        $session->remove('invitation_uuid');
        $session->remove('game_id');

        $frontendUrl = rtrim($_ENV['FRONTEND_URL'] ?? 'http://localhost:5173', '/');

        return new JsonResponse([
            'success' => true,
            'redirect' => $frontendUrl.'/joined',
        ], Response::HTTP_OK, ['X-Accel-Buffering' => 'no']);
    }
}
