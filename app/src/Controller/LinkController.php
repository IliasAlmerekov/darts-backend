<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;


class LinkController extends AbstractController
{
    #[Route('/gameLink')]
    public function index(): Response
    {
        return $this->render(
            'gamelink/index.html.twig', []
        );
    }
}
