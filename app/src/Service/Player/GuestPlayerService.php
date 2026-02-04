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
use App\Repository\UserRepositoryInterface;
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
    private const int MAX_USERNAME_LENGTH = 30;
    private const int SUGGESTION_LIMIT = 3;

    /**
     * @param UserRepositoryInterface          $userRepository
     * @param PlayerManagementServiceInterface $playerManagementService
     * @param UserPasswordHasherInterface      $passwordHasher
     * @param EntityManagerInterface           $entityManager
     */
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private PlayerManagementServiceInterface $playerManagementService,
        private UserPasswordHasherInterface $passwordHasher,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param Game   $game
     * @param string $username
     *
     * @return array{playerId:int,name:string,position:int|null}
     */
    #[Override]
    public function createGuestPlayer(Game $game, string $username): array
    {
        $normalized = trim($username);
        if ('' === $normalized) {
            throw new \InvalidArgumentException('Username cannot be empty');
        }

        if (null !== $this->userRepository->findOneByUsername($normalized)) {
            throw new UsernameAlreadyTakenException($normalized, $this->buildSuggestions($normalized));
        }

        $guest = new User();
        $guest
            ->setUsername($normalized)
            ->setEmail($this->generateGuestEmail())
            ->setPassword($this->passwordHasher->hashPassword($guest, $this->generateRandomPassword()))
            ->setRoles(['ROLE_GUEST'])
            ->setIsGuest(true);

        $this->entityManager->persist($guest);
        $this->entityManager->flush();

        $guestId = $guest->getId();
        $gameId = $game->getGameId();
        if (null === $gameId) {
            throw new GameIdMissingException();
        }
        if (null === $guestId) {
            throw new \RuntimeException('Failed to create guest player');
        }

        $gamePlayer = $this->playerManagementService->addPlayer($gameId, $guestId);

        return [
            'playerId' => $guestId,
            'name' => (string) $guest->getDisplayName(),
            'position' => $gamePlayer->getPosition(),
        ];
    }

    /**
     * @param string $base
     *
     * @return list<string>
     */
    private function buildSuggestions(string $base): array
    {
        $suggestions = [];
        $suffix = 2;
        while (count($suggestions) < self::SUGGESTION_LIMIT && $suffix < 20) {
            $candidate = $base.' '.$suffix;
            if ($this->getLength($candidate) <= self::MAX_USERNAME_LENGTH
                && null === $this->userRepository->findOneByUsername($candidate)
            ) {
                $suggestions[] = $candidate;
            }
            $suffix++;
        }

        return $suggestions;
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

    /**
     * @param string $value
     *
     * @return int
     */
    private function getLength(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value);
        }

        return strlen($value);
    }
}
