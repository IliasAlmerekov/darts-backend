<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\RegistrationController;
use App\Service\Registration\RegistrationServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class RegistrationControllerTest extends TestCase
{
    private RegistrationController $controller;
    private ContainerInterface&MockObject $container;

    protected function setUp(): void
    {
        $this->controller = new RegistrationController();
        $this->container = $this->createMock(ContainerInterface::class);
        $this->controller->setContainer($this->container);
    }

    public function testRegisterReturnsCreatedOnSuccess(): void
    {
        $request = Request::create('/api/register', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{"username":"new","email":"new@example.com","plainPassword":"pass"}');

        $service = $this->createMock(RegistrationServiceInterface::class);
        $service->expects($this->once())
            ->method('register')
            ->with([
                'username' => 'new',
                'email' => 'new@example.com',
                'plainPassword' => 'pass',
            ])
            ->willReturn([
                'success' => true,
                'message' => 'Registrierung erfolgreich',
                'redirect' => '/',
                'status' => Response::HTTP_CREATED,
            ]);

        $response = $this->controller->register($request, $service);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(Response::HTTP_CREATED, $response->getStatusCode());
    }

    public function testRegisterReturnsBadRequestOnInvalidJson(): void
    {
        $request = Request::create('/api/register', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: 'invalid');
        $service = $this->createMock(RegistrationServiceInterface::class);
        $service->expects($this->never())->method('register');

        $response = $this->controller->register($request, $service);

        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testRegisterReturnsServiceErrorPayload(): void
    {
        $request = Request::create('/api/register', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{"username":""}');
        $service = $this->createMock(RegistrationServiceInterface::class);
        $service->expects($this->once())
            ->method('register')
            ->willReturn([
                'success' => false,
                'message' => 'Registrierung fehlgeschlagen. Bitte überprüfe deine Eingaben.',
                'status' => Response::HTTP_BAD_REQUEST,
                'errors' => ['username' => ['Required']],
            ]);

        $response = $this->controller->register($request, $service);

        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertArrayHasKey('errors', $data);
    }
}
