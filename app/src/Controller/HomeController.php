<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;


class HomeController extends AbstractController
{
    #[Route('/', name: 'home')]
    public function index(): Response
    {
        return $this->render(
            'home/index.html.twig', []
        );
    }

    #[Route('/success', name: 'success')]
    public function successfullLogin(): Response
    {
        return $this->render(
            'success/index.html.twig', []
        );
    }
}
