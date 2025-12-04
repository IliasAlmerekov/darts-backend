<?php declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationFormType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Controller to handle user registration.
 */
final class RegistrationController extends AbstractController
{
    #[Route('/api/register', name: 'app_register', methods: ['POST'])]
    public function register(Request $request, UserPasswordHasherInterface $userPasswordHasher, EntityManagerInterface $entityManager): Response
    {

        $data = json_decode($request->getContent(), true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            return $this->json([
                'success' => false,
                'message' => 'Ungültiger JSON-Body. Bitte überprüfe das Datenformat.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);

        $form->submit($data);

        if ($form->isSubmitted() && $form->isValid()) {

            $plainPassword = $form->get('plainPassword')->getData();
            $username = $form->get('username')->getData();
            $email = $form->get('email')->getData();

            $user->setUsername($username);
            $user->setEmail($email);
            $user->setPassword($userPasswordHasher->hashPassword($user, $plainPassword));
            $user->setRoles(['ROLE_PLAYER']);

            $entityManager->persist($user);
            $entityManager->flush();

            return $this->json([
                'success' => true,
                'message' => 'Registrierung erfolgreich',
                'redirect' => '/'
            ], Response::HTTP_CREATED);
        }

        $errors = [];
        foreach ($form->getErrors(true) as $error) {
            // FormError always has getOrigin() and getMessage() methods
            /** @var \Symfony\Component\Form\FormError $error */
            $origin = $error->getOrigin();
            $fieldName = $origin !== null ? $origin->getName() : 'global';
            $errors[$fieldName][] = $error->getMessage();
        }

        return $this->json([
            'success' => false,
            'message' => 'Registrierung fehlgeschlagen. Bitte überprüfe deine Eingaben.',
            'errors' => $errors,
        ], Response::HTTP_BAD_REQUEST);
    }
}
