<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\InvitationController;
use App\Entity\Game;
use App\Entity\Invitation;
use App\Exception\Game\GameNotFoundException;
use App\Service\Invitation\InvitationServiceInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Uid\Uuid;

#[AllowMockObjectsWithoutExpectations]
final class InvitationControllerTest extends TestCase
{
    private InvitationController $controller;
    private ContainerInterface&MockObject $container;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->controller = new InvitationController('http://example.test/app', $this->logger);
        $this->container = $this->createMock(ContainerInterface::class);
        $this->controller->setContainer($this->container);
    }

    public function testHealthReturnsReadyPayload(): void
    {
        $response = $this->controller->health();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(
            ['success' => true, 'status' => 'ok'],
            json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR)
        );
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
                'invitationLink' => '/api/invite/join/'.$existingUuid,
                'users' => [],
            ]);

        $response = $this->controller->createInvitation($game, $invitationService);

        $this->assertIsArray($response);
        $this->assertTrue($response['success']);
        $this->assertEquals($gameId, $response['gameId']);
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
                'invitationLink' => '/api/invite/join/some-uuid',
                'users' => [],
            ]);

        $response = $this->controller->createInvitation($game, $invitationService);

        $this->assertIsArray($response);
        $this->assertTrue($response['success']);
        $this->assertEquals($gameId, $response['gameId']);
    }

    public function testJoinInvitationThrowsWhenGameIdMissing(): void
    {
        $invitation = $this->createMock(Invitation::class);
        $invitation->method('getGameId')->willReturn(null);
        $invitation->method('getUuid')->willReturn('missing-game-id');

        $session = $this->createMock(SessionInterface::class);
        $invitationService = $this->createMock(InvitationServiceInterface::class);
        $invitationService->expects(self::never())->method('assertGameJoinable');

        $this->logger->expects(self::once())
            ->method('warning')
            ->with('Invite join request is missing a game id.', [
                'invitationUuid' => 'missing-game-id',
            ]);

        $this->expectException(GameNotFoundException::class);

        $this->controller->joinInvitation($invitation, $session, $invitationService);
    }

    public function testJoinInvitationStoresSessionAndRedirects(): void
    {
        $gameId = 77;
        $uuid = Uuid::v4()->toRfc4122();

        $invitation = $this->createMock(Invitation::class);
        $invitation->method('getGameId')->willReturn($gameId);
        $invitation->method('getUuid')->willReturn($uuid);

        $session = $this->createMock(SessionInterface::class);
        $session->expects(self::once())
            ->method('remove')
            ->with('invitation_uuid');

        $setCalls = [];
        $session->expects(self::exactly(2))
            ->method('set')
            ->willReturnCallback(static function (string $key, mixed $value) use (&$setCalls): void {
                $setCalls[$key] = $value;
            });

        $invitationService = $this->createMock(InvitationServiceInterface::class);
        $invitationService->expects(self::once())
            ->method('assertGameJoinable')
            ->with($gameId);

        $this->logger->expects(self::exactly(2))
            ->method('info');

        $response = $this->controller->joinInvitation($invitation, $session, $invitationService);

        self::assertSame($gameId, $setCalls['game_id']);
        self::assertSame($uuid, $setCalls['invitation_uuid']);
        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('http://example.test/app/', $response->headers->get('Location'));
    }

    public function testProcessInvitationDelegatesToService(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $expectedResponse = new Response('ok', Response::HTTP_ACCEPTED);

        $invitationService = $this->createMock(InvitationServiceInterface::class);
        $invitationService->expects(self::once())
            ->method('processInvitation')
            ->with($session, null)
            ->willReturn($expectedResponse);

        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(null);

        $this->container->method('has')
            ->with('security.token_storage')
            ->willReturn(true);
        $this->container->method('get')
            ->with('security.token_storage')
            ->willReturn($tokenStorage);

        $this->logger->expects(self::exactly(2))
            ->method('info');

        $response = $this->controller->processInvitation($session, $invitationService);

        self::assertSame($expectedResponse, $response);
    }
}
