<?php

namespace App\Controller;

use App\Entity\Invitation;
use App\Repository\GamePlayersRepository;
use App\Repository\InvitationRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

class InvitationController extends AbstractController
{
    #[Route('/invite/create/{id}', name: 'create_invitation')]
    public function createInvitation(int $id, Request $request, EntityManagerInterface $entityManager, InvitationRepository $invitationRepository, GamePlayersRepository $gamePlayersRepository, UserRepository $userRepository): Response
    {
        $invitation = $invitationRepository->findOneBy(['gameId' => $id]);

        if ($invitation === null) {
            $uuid = Uuid::v4();
            $invitation = new Invitation();
            $invitation->setUuid($uuid);
            $invitation->setGameId($id);

            $entityManager->persist($invitation);
            $entityManager->flush();
        }

        $players = $gamePlayersRepository->findBy(['gameId' => $id]);

        $playerIds = array_map(fn($player) => $player->getPlayerId(), $players);

        $users = $userRepository->findBy(['id' => $playerIds]);

        $invitationLink = $this->generateUrl('join_invitation', ['uuid' => $invitation->getUuid()], UrlGeneratorInterface::ABSOLUTE_URL);

        return $this->render('invitation/create.html.twig', [
            'invitationLink' => $invitationLink,
            'users' => $users
        ]);
    }

    #[Route('/invite/join/{uuid}', name: 'join_invitation')]
    public function joinInvitation(string $uuid, InvitationRepository $invitationRepository, Request $request): Response
    {
        $invitation = $invitationRepository->findOneBy(['uuid' => $uuid]);
        if (!$invitation) {
            return $this->render('invitation/not_found.html.twig');
        }
        $request->getSession()->set('invitation_uuid', $uuid);

        return $this->redirectToRoute('app_login');
    }
}
