<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Registration;

use App\Dto\RegistrationRequest;
use App\Entity\User;
use App\Exception\Registration\RegistrationAccountExistsException;
use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Override;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Handles user registration logic.
 */
final readonly class RegistrationService implements RegistrationServiceInterface
{
    /**
     * @param UserPasswordHasherInterface $passwordHasher
     * @param EntityManagerInterface      $entityManager
     */
    public function __construct(
        private UserPasswordHasherInterface $passwordHasher,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param RegistrationRequest $request
     *
     * @return User
     */
    #[Override]
    public function register(RegistrationRequest $request): User
    {
        $user = new User();
        $user->setUsername(trim($request->username));
        $user->setEmail(trim($request->email));
        $user->setPassword($this->passwordHasher->hashPassword($user, $request->plainPassword));
        $user->setRoles(['ROLE_PLAYER']);
        $user->setRegisteredAt(new DateTimeImmutable());

        // The unique indexes on email and username are the only duplicate check, so two
        // parallel registrations with the same data cannot both pass it.
        try {
            $this->entityManager->persist($user);
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $exception) {
            throw new RegistrationAccountExistsException($exception);
        }

        return $user;
    }
}
