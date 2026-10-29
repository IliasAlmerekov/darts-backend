<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\RegistrationController;
use App\Dto\RegistrationRequest;
use App\Entity\User;
use App\Service\Registration\RegistrationServiceInterface;
use LogicException;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;

/**
 * The controller registers the player and logs them in. Mapping, validation errors,
 * malformed JSON, the rate limit and the login body are covered through the kernel in
 * RegistrationEndpointTest.
 */
final class RegistrationControllerTest extends TestCase
{
    public function testRegisterLogsTheNewPlayerInAndAnswers201(): void
    {
        $payload = $this->payload();
        $user = new User();
        $service = $this->createMock(RegistrationServiceInterface::class);
        $service->expects($this->once())
            ->method('register')
            ->with($this->identicalTo($payload))
            ->willReturn($user);

        $loginResponse = new JsonResponse(['success' => true]);
        $security = $this->createMock(Security::class);
        $security->expects($this->once())
            ->method('login')
            ->with(
                $this->identicalTo($user),
                'form_login',
                'main',
                $this->callback(static fn (array $badges): bool => 1 === count($badges) && $badges[0] instanceof RememberMeBadge),
            )
            ->willReturn($loginResponse);

        $response = (new RegistrationController())->register($payload, $service, $security);

        $this->assertSame($loginResponse, $response);
        $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode());
    }

    public function testRegisterFailsWhenTheLoginReturnsNoResponse(): void
    {
        $service = $this->createStub(RegistrationServiceInterface::class);
        $service->method('register')->willReturn(new User());
        $security = $this->createStub(Security::class);
        $security->method('login')->willReturn(null);

        $this->expectException(LogicException::class);

        (new RegistrationController())->register($this->payload(), $service, $security);
    }

    private function payload(): RegistrationRequest
    {
        $payload = new RegistrationRequest();
        $payload->username = 'new';
        $payload->email = 'new@db-n.com';
        $payload->plainPassword = 'password';

        return $payload;
    }
}
