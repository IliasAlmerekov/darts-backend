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
use Override;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormErrorIterator;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Handles user registration logic.
 */
final readonly class RegistrationService implements RegistrationServiceInterface
{
    /**
     * @param FormFactoryInterface        $formFactory
     * @param UserPasswordHasherInterface $passwordHasher
     * @param EntityManagerInterface      $entityManager
     */
    public function __construct(private FormFactoryInterface $formFactory, private UserPasswordHasherInterface $passwordHasher, private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{success:bool,message:string,status:int,redirect?:string,errors?:array<string,array<int,string>>}
     */
    #[Override]
    public function register(array $data): array
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

        return [
            'success' => true,
            'message' => 'Registrierung erfolgreich',
            'redirect' => '/',
            'status' => Response::HTTP_CREATED,
        ];
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
