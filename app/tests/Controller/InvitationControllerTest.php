<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\InvitationController;
use App\Entity\Game;
use App\Entity\Invitation;
use App\Service\Invitation\InvitationServiceInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Uid\Uuid;

#[AllowMockObjectsWithoutExpectations]
final class InvitationControllerTest extends TestCase
{
    private InvitationController $controller;
    private ContainerInterface&MockObject $container;

    protected function setUp(): void
    {
        $this->controller = new InvitationController();
        $this->container = $this->createMock(ContainerInterface::class);
        $this->controller->setContainer($this->container);
    }

    public function testCreateInvitationReusesExistingInvitation(): void
    {
        $gameId = 42;
        $existingUuid = Uuid::v4()->toRfc4122();

        $game = $this->createMock(Game::class);
        $game->method('getGameId')->willReturn($gameId);

        $existingInvitation = $this->createMock(Invitation::class);
        $existingInvitation->method('getUuid')->willReturn($existingUuid);

        $invitationService = $this->createMock(InvitationServiceInterface::class);
        $invitationService->expects($this->once())
            ->method('getInvitationPayload')
            ->with($game)
            ->willReturn([
                'success' => true,
                'gameId' => $gameId,
                'invitationLink' => '/invite/'.$existingUuid,
                'users' => [],
            ]);

        $response = $this->controller->createInvitation($game, $invitationService);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $data = json_decode((string) $response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertEquals($gameId, $data['gameId']);
    }

    public function testCreateInvitationCreatesNewInvitationWhenNotExists(): void
    {
        $gameId = 99;

        $game = $this->createMock(Game::class);
        $game->method('getGameId')->willReturn($gameId);

        $createdInvitation = new Invitation();
        $createdInvitation->setGameId($gameId);
        $createdInvitation->setUuid(Uuid::v4());

        $invitationService = $this->createMock(InvitationServiceInterface::class);
        $invitationService->expects($this->once())
            ->method('getInvitationPayload')
            ->with($game)
            ->willReturn([
                'success' => true,
                'gameId' => $gameId,
                'invitationLink' => '/invite/some-uuid',
                'users' => [],
            ]);

        $response = $this->controller->createInvitation($game, $invitationService);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertEquals($gameId, $data['gameId']);
    }
}
