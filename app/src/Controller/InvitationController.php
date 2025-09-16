<?php

namespace App\Controller;

use App\Entity\Invitation;
use App\Repository\InvitationRepository;
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
    public function createInvitation(int $id, EntityManagerInterface $entityManager, InvitationRepository $invitationRepository): Response
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

        $invitationLink = $this->generateUrl('join_invitation', ['uuid' => $invitation->getUuid()], UrlGeneratorInterface::ABSOLUTE_URL);

        return $this->render('invitation/index.html.twig', [
            'invitationLink' => $invitationLink,
        ]);
    }

    #[Route('/invite/join/{uuid}', name: 'join_invitation')]
    public function joinInvitation(string $uuid, InvitationRepository $invitationRepository, Request $request): Response
    {
        $invitation = $invitationRepository->findOneBy(['uuid' => $uuid]);
        if (!$invitation) {
            dd('Invitation not found');
        }
        $request->getSession()->set('invitation_uuid', $uuid);

        return $this->redirectToRoute('app_login');
    }
}
