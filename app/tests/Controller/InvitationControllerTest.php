<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\InvitationController;
use App\Entity\GamePlayers;
use App\Entity\Invitation;
use App\Entity\User;
use App\Repository\GamePlayersRepository;
use App\Repository\GamePlayersRepositoryInterface;
use App\Repository\InvitationRepository;
use App\Repository\InvitationRepositoryInterface;
use App\Repository\UserRepository;
use App\Repository\UserRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;

class InvitationControllerTest extends TestCase
{
    private InvitationController $controller;
    private ContainerInterface&MockObject $container;

    protected function setUp(): void
    {
        $this->controller = new InvitationController();

        $this->container = $this->createMock(ContainerInterface::class);
        $this->controller->setContainer($this->container);
    }

    /**
     * Test: Invitation existiert bereits -> wird wiederverwendet
     */
    public function testCreateInvitationReusesExistingInvitation(): void
    {
        $gameId = 42;
        $existingUuid = Uuid::v4()->toRfc4122();

        $request = Request::create('/api/invite/create/42', 'GET');

        $existingInvitation = $this->createMock(Invitation::class);
        $existingInvitation->method('getUuid')->willReturn($existingUuid);

        $invitationRepo = $this->createMock(InvitationRepositoryInterface::class);
        $invitationRepo->expects($this->once())
            ->method('findOneBy')
            ->with(['gameId' => $gameId])
            ->willReturn($existingInvitation);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        // EntityManager darf NICHT aufgerufen werden
        $entityManager->expects($this->never())->method('persist');
        $entityManager->expects($this->never())->method('flush');

        $gamePlayersRepo = $this->createMock(GamePlayersRepositoryInterface::class);
        $gamePlayersRepo->method('findByGameId')->willReturn([]);

        $userRepo = $this->createMock(UserRepositoryInterface::class);
        $userRepo->method('findBy')->willReturn([]);

        $router = $this->createMock(RouterInterface::class);
        $router->expects($this->once())
            ->method('generate')
            ->with('join_invitation', ['uuid' => $existingUuid])
            ->willReturn('/invite/' . $existingUuid);

        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturn('');

        $this->container->method('has')->willReturnMap([
            ['router', true],
            ['twig', true]
        ]);
        $this->container->method('get')->willReturnCallback(function ($service) use ($router, $twig) {
            return match ($service) {
                'router' => $router,
                'twig' => $twig,
                default => null
            };
        });

        $response = $this->controller->createInvitation(
            $gameId,
            $request,
            $entityManager,
            $invitationRepo,
            $gamePlayersRepo,
            $userRepo
        );

        $this->assertInstanceOf(Response::class, $response);
    }

    /**
     * Test: Neue Invitation wird erstellt
     */
    public function testCreateInvitationCreatesNewInvitationWhenNotExists(): void
    {
        $gameId = 99;

        $request = Request::create('/api/invite/create/99', 'GET');

        $invitationRepo = $this->createMock(InvitationRepositoryInterface::class);
        $invitationRepo->expects($this->once())
            ->method('findOneBy')
            ->with(['gameId' => $gameId])
            ->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function ($invitation) use ($gameId) {
                if (!$invitation instanceof Invitation) {
                    return false;
                }
                if ($invitation->getGameId() !== $gameId) {
                    return false;
                }
                $uuid = $invitation->getUuid();
                if (!is_string($uuid) || empty($uuid)) {
                    return false;
                }
                return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid) === 1;
            }));
        $entityManager->expects($this->once())->method('flush');

        $gamePlayersRepo = $this->createMock(GamePlayersRepositoryInterface::class);
        $gamePlayersRepo->method('findByGameId')->willReturn([]);

        $userRepo = $this->createMock(UserRepositoryInterface::class);
        $userRepo->method('findBy')->willReturn([]);

        $router = $this->createMock(RouterInterface::class);
        $router->method('generate')->willReturn('/invite/some-uuid');

        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturn('');

        $this->container->method('has')->willReturnMap([
            ['router', true],
            ['twig', true]
        ]);
        $this->container->method('get')->willReturnCallback(function ($service) use ($router, $twig) {
            return match ($service) {
                'router' => $router,
                'twig' => $twig,
                default => null
            };
        });

        $response = $this->controller->createInvitation(
            $gameId,
            $request,
            $entityManager,
            $invitationRepo,
            $gamePlayersRepo,
            $userRepo
        );

        $this->assertInstanceOf(Response::class, $response);
    }
}
