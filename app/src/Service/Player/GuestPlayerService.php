<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Player;

use App\Entity\Game;
use App\Entity\User;
use App\Exception\Game\GameIdMissingException;
use App\Exception\Request\UsernameAlreadyTakenException;
use App\Repository\GamePlayersRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Override;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Creates guest users and adds them to games.
 *
 * @psalm-suppress UnusedClass Reason: service is wired via DI.
 */
final readonly class GuestPlayerService implements GuestPlayerServiceInterface
{
    /**
     * @param GamePlayersRepositoryInterface   $gamePlayersRepository
     * @param PlayerManagementServiceInterface $playerManagementService
     * @param UserPasswordHasherInterface      $passwordHasher
     * @param EntityManagerInterface           $entityManager
     */
    public function __construct(
        private GamePlayersRepositoryInterface $gamePlayersRepository,
        private PlayerManagementServiceInterface $playerManagementService,
        private UserPasswordHasherInterface $passwordHasher,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param Game   $game
     * @param string $username
     *
     * @return array{playerId:int,name:string,position:int|null,isGuest:bool}
     */
    #[Override]
    public function createGuestPlayer(Game $game, string $username): array
    {
        $normalized = trim($username);
        if ('' === $normalized) {
            throw new \InvalidArgumentException('Username cannot be empty');
        }

        $gameId = $game->getGameId();
        if (null === $gameId) {
            throw new GameIdMissingException();
        }

        if ($this->isNameTakenInGame($gameId, $normalized)) {
            throw new UsernameAlreadyTakenException($normalized, []);
        }

        $guest = new User();
        $guest
            ->setUsername($this->generateGuestUsername())
            ->setDisplayName($normalized)
            ->setEmail($this->generateGuestEmail())
            ->setPassword($this->passwordHasher->hashPassword($guest, $this->generateRandomPassword()))
            ->setRoles(['ROLE_GUEST'])
            ->setIsGuest(true);

        $this->entityManager->persist($guest);

        $gamePlayer = $this->playerManagementService->addPlayerEntity($gameId, $guest, flush: false);
        $this->entityManager->flush();

        $guestId = $guest->getId();
        if (null === $guestId) {
            throw new \RuntimeException('Failed to create guest player');
        }

        return [
            'playerId' => $guestId,
            'name' => $normalized,
            'position' => $gamePlayer->getPosition(),
            'isGuest' => true,
        ];
    }

    /**
     * @param int    $gameId
     * @param string $name
     *
     * @return bool
     */
    private function isNameTakenInGame(int $gameId, string $name): bool
    {
        return $this->gamePlayersRepository->existsNameInGame($gameId, $name);
    }

    /**
     * @return string
     */
    private function generateGuestUsername(): string
    {
        return sprintf('guest_%s', Uuid::v4()->toBase58());
    }

    /**
     * @return string
     */
    private function generateGuestEmail(): string
    {
        return sprintf('guest+%s@guest.local', Uuid::v4()->toRfc4122());
    }

    /**
     * @return string
     */
    private function generateRandomPassword(): string
    {
        return bin2hex(random_bytes(16));
    }
}
