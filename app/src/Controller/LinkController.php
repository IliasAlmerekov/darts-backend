<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;


class LinkController extends AbstractController
{
    #[Route('/gameLink/{id}', name: 'gameLink', methods: ['GET'])]
    public function index(int $id): Response
    {
        return $this->render(
            'gamelink/index.html.twig', [
                'gameId' => $id,
            ]
        );
    }
}
