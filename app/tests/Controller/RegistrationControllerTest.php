<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\RegistrationController;
use App\Dto\RegistrationRequest;
use App\Service\Registration\RegistrationServiceInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The controller only hands the mapped payload to the service. Mapping, validation errors,
 * malformed JSON and the rate limit are covered through the kernel in RegistrationEndpointTest.
 */
final class RegistrationControllerTest extends TestCase
{
    public function testRegisterReturnsTheServiceLoginResponse(): void
    {
        $payload = new RegistrationRequest();
        $payload->username = 'new';
        $payload->email = 'new@example.com';
        $payload->plainPassword = 'password';

        $loginResponse = new JsonResponse(['success' => true], Response::HTTP_CREATED);
        $service = $this->createMock(RegistrationServiceInterface::class);
        $service->expects($this->once())
            ->method('register')
            ->with($this->identicalTo($payload))
            ->willReturn($loginResponse);

        $response = (new RegistrationController())->register($payload, $service);

        $this->assertSame($loginResponse, $response);
    }
}
