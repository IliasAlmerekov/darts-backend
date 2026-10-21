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
use LogicException;
use Override;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;

/**
 * Handles user registration logic.
 */
final readonly class RegistrationService implements RegistrationServiceInterface
{
    /**
     * @param UserPasswordHasherInterface $passwordHasher
     * @param EntityManagerInterface      $entityManager
     * @param Security                    $security
     */
    public function __construct(
        private UserPasswordHasherInterface $passwordHasher,
        private EntityManagerInterface $entityManager,
        private Security $security,
    ) {
    }

    /**
     * @param RegistrationRequest $request
     *
     * @return Response
     */
    #[Override]
    public function register(RegistrationRequest $request): Response
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

        return $this->logIn($user);
    }

    /**
     * Logs the new player in on the main firewall, as a form login would.
     *
     * The form_login success handler builds the response, so registration returns the same
     * body as login, including the invitation join or its `invitationError`. The remember-me
     * badge lets RememberedLoginSubscriber start a Remembered login for the player.
     *
     * @param User $user
     *
     * @return Response
     */
    private function logIn(User $user): Response
    {
        $response = $this->security->login($user, 'form_login', 'main', [new RememberMeBadge()]);
        if (!$response instanceof Response) {
            throw new LogicException('The form_login success handler returned no response.');
        }

        // Only a successful login becomes 201; a failed invitation lookup keeps its own status.
        if (Response::HTTP_OK === $response->getStatusCode()) {
            $response->setStatusCode(Response::HTTP_CREATED);
        }

        return $response;
    }
}
