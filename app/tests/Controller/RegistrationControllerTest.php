<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\RegistrationController;
use App\Exception\Request\InvalidJsonBodyException;
use App\Service\Registration\RegistrationServiceInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[AllowMockObjectsWithoutExpectations]
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

    public function testRegisterReturnsTheServiceLoginResponseOnSuccess(): void
    {
        $request = Request::create('/api/register', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{"username":"new","email":"new@example.com","plainPassword":"pass","_csrf_token":"csrf-token"}');

        $loginResponse = new JsonResponse(['success' => true], Response::HTTP_CREATED);
        $service = $this->createMock(RegistrationServiceInterface::class);
        $service->expects($this->once())
            ->method('register')
            ->with([
                'username' => 'new',
                'email' => 'new@example.com',
                'plainPassword' => 'pass',
                '_csrf_token' => 'csrf-token',
            ])
            ->willReturn($loginResponse);

        $response = $this->controller->register($request, $service);

        $this->assertSame($loginResponse, $response);
    }

    public function testRegisterReturnsBadRequestOnInvalidJson(): void
    {
        $request = Request::create('/api/register', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: 'invalid');
        $service = $this->createMock(RegistrationServiceInterface::class);
        $service->expects($this->never())->method('register');

        $this->expectException(InvalidJsonBodyException::class);
        $this->controller->register($request, $service);
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

        $this->assertIsArray($response);
        $this->assertSame(Response::HTTP_BAD_REQUEST, $response['status']);
        $this->assertFalse($response['success']);
        $this->assertArrayHasKey('errors', $response);
    }
}
