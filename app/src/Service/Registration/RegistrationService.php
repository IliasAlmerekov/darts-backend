<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Registration;

use App\Entity\User;
use App\Form\RegistrationFormType;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Override;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormErrorIterator;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;

/**
 * Handles user registration logic.
 */
final readonly class RegistrationService implements RegistrationServiceInterface
{
    /**
     * @param FormFactoryInterface        $formFactory
     * @param UserPasswordHasherInterface $passwordHasher
     * @param EntityManagerInterface      $entityManager
     * @param Security                    $security
     */
    public function __construct(
        private FormFactoryInterface $formFactory,
        private UserPasswordHasherInterface $passwordHasher,
        private EntityManagerInterface $entityManager,
        private Security $security,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return Response|array{success:false,message:string,status:int,errors:array<string,array<int,string>>}
     */
    #[Override]
    public function register(array $data): Response|array
    {
        $user = new User();
        $form = $this->formFactory->create(RegistrationFormType::class, $user);
        $form->submit($data);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return [
                'success' => false,
                'message' => 'Registrierung fehlgeschlagen. Bitte überprüfe deine Eingaben.',
                'status' => Response::HTTP_BAD_REQUEST,
                'errors' => $this->collectErrors($form->getErrors(true)),
            ];
        }

        $plainPassword = (string) $form->get('plainPassword')->getData();
        $user->setUsername((string) $form->get('username')->getData());
        $user->setEmail((string) $form->get('email')->getData());
        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
        $user->setRoles(['ROLE_PLAYER']);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

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

    /**
     * @param FormErrorIterator<FormError|FormErrorIterator> $errors
     *
     * @return array<string, array<int, string>>
     */
    private function collectErrors(FormErrorIterator $errors): array
    {
        $result = [];
        foreach ($errors as $error) {
            if ($error instanceof FormError) {
                $origin = $error->getOrigin();
                $fieldName = null !== $origin ? $origin->getName() : 'global';
                $result[$fieldName][] = $error->getMessage();
            }
        }

        return $result;
    }
}
