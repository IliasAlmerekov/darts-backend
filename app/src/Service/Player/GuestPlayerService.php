<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Player;

use App\Entity\User;
use App\Exception\Request\UsernameAlreadyTakenException;
use App\Repository\GamePlayersRepositoryInterface;
use Override;
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
     */
    public function __construct(
        private GamePlayersRepositoryInterface $gamePlayersRepository,
        private PlayerManagementServiceInterface $playerManagementService,
    ) {
    }

    /**
     * @param int    $gameId
     * @param string $username
     *
     * @return array{playerId:int,name:string,position:int|null,placement:int|null,isGuest:bool}
     */
    #[Override]
    public function createGuestPlayer(int $gameId, string $username): array
    {
        $normalized = trim($username);
        if ('' === $normalized) {
            throw new \InvalidArgumentException('Username cannot be empty');
        }

        // The seat module checks the name under the game lock, so a parallel join or guest
        // waits and then gets the next position.
        $gamePlayer = $this->playerManagementService->addNewPlayer($gameId, function () use ($gameId, $normalized): User {
            if ($this->isNameTakenInGame($gameId, $normalized)) {
                throw new UsernameAlreadyTakenException($normalized, []);
            }

            return (new User())
                ->setUsername($this->generateGuestUsername())
                ->setDisplayName($normalized)
                ->setEmail($this->generateGuestEmail())
                ->setPassword('guest_no_auth_'.bin2hex(random_bytes(8)))
                ->setRoles(['ROLE_GUEST'])
                ->setIsGuest(true);
        });

        $guestId = $gamePlayer->getPlayer()?->getId();
        if (null === $guestId) {
            throw new \RuntimeException('Failed to create guest player');
        }

        return [
            'playerId' => $guestId,
            'name' => $normalized,
            'position' => $gamePlayer->getPosition(),
            'placement' => $gamePlayer->getPlacement(),
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
}
