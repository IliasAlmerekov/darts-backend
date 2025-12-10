<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\Security\SecurityServiceInterface;
use LogicException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller to handle user login and logout.
 */
final class SecurityController extends AbstractController
{
    /**
     * Returns login state or last error for API clients.
     *
     * @param AuthenticationUtils $authenticationUtils
     *
     * @return Response
     */
    #[Route(path: '/api/login', name: 'app_login', methods: ['GET', 'POST'], format: 'json')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        $user = $this->getUser();
        if ($user) {
            $id = method_exists($user, 'getId') ? $user->getId() : null;

            return $this->json([
                'success' => true,
                'id' => $id,
                'username' => $user->getUserIdentifier(),
                'roles' => $user instanceof User ? $user->getStoredRoles() : $user->getRoles(),
                'redirect' => '/api/login/success',
            ]);
        }

        $error = $authenticationUtils->getLastAuthenticationError();
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->json([
            'success' => false,
            'last_username' => $lastUsername,
            'error' => $error?->getMessage(),
        ], $error ? Response::HTTP_UNAUTHORIZED : Response::HTTP_OK);
    }

    /**
     * Handles post-login redirection logic for API clients.
     *
     * @param Request                  $request
     * @param SecurityServiceInterface $securityService
     *
     * @return Response
     */
    #[Route('/api/login/success', name: 'login_success', format: 'json')]
    public function loginSuccess(Request $request, SecurityServiceInterface $securityService): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'User not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        return $securityService->buildLoginSuccessResponse($user, $request->getSession());
    }

    #[Route(path: '/api/logout', name: 'app_logout')]
    /**
     * @return void
     */
    public function logout(): void
    {
        throw new LogicException(
            'This method can be blank - it will be intercepted by the logout key on your firewall.'
        );
    }
}
