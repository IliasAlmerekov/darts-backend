<?php

namespace App\Controller;

use App\Entity\Game;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;


class HomeController extends AbstractController
{
    #[Route('/', name: 'home', methods: ['GET'])]
    public function index(): Response
    {
        return $this->redirectToRoute('app_login');
    }
//    #[Route('/create-room', name: 'create_room', methods: ['POST'])]
//    public function createRoom(EntityManagerInterface $entityManager): Response
//    {
//        $game = new Game();
//        $game->setDate(new \DateTime());
//
//        $entityManager->persist($game);
//        $entityManager->flush();
//
//        return $this->redirectToRoute('gameLink', ['id' => $game->getGameId()]);
//    }
//
//    #[Route('/success', name: 'success')]
//    public function successfullLogin(): Response
//    {
//        return $this->render(
//            'success/index.html.twig', []
//        );
//    }
}
