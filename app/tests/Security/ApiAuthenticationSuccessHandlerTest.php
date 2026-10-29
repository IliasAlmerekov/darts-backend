<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Dto\LoginSuccessDto;
use App\Entity\User;
use App\Exception\Security\UserNotAuthenticatedException;
use App\Security\ApiAuthenticationSuccessHandler;
use App\Security\LoginSuccessResponder;
use App\Service\Security\SecurityServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

final class ApiAuthenticationSuccessHandlerTest extends TestCase
{
    /**
     * @return void
     */
    public function testApiRequestTakesTheInvitationAndAnswersWithTheLoginBody(): void
    {
        $securityService = $this->createMock(SecurityServiceInterface::class);
        $handler = new ApiAuthenticationSuccessHandler(
            $securityService,
            new LoginSuccessResponder('http://frontend.test/'),
            $this->createStub(UrlGeneratorInterface::class),
        );

        $request = Request::create('/api/login', 'POST');
        $session = new Session(new MockArraySessionStorage());
        $session->set('invitation_uuid', 'uuid');
        $session->set('game_id', 42);
        $request->setSession($session);

        $user = (new User())->setUsername('alice')->setEmail('alice@test.dev')->setRoles(['ROLE_PLAYER']);
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $securityService
            ->expects($this->once())
            ->method('resolveLoginSuccess')
            ->with($user, 42)
            ->willReturn(new LoginSuccessDto($user, '/joined', gameId: 42));

        $response = $handler->onAuthenticationSuccess($request, $token);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no', $response->headers->get('X-Accel-Buffering'));
        self::assertSame(
            ['success' => true, 'roles' => ['ROLE_PLAYER'], 'id' => null, 'email' => 'alice@test.dev', 'username' => 'alice', 'redirect' => 'http://frontend.test/joined', 'gameId' => 42],
            json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR),
        );
        self::assertFalse($session->has('invitation_uuid'));
        self::assertFalse($session->has('game_id'));
    }

    /**
     * @return void
     */
    public function testNonApiRequestRedirectsToLoginSuccess(): void
    {
        $securityService = $this->createStub(SecurityServiceInterface::class);
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $handler = new ApiAuthenticationSuccessHandler($securityService, new LoginSuccessResponder('http://frontend.test'), $urlGenerator);

        $request = Request::create('/login', 'POST');
        $token = $this->createStub(TokenInterface::class);

        $urlGenerator
            ->expects($this->once())
            ->method('generate')
            ->with('login_success')
            ->willReturn('/api/login/success');

        $response = $handler->onAuthenticationSuccess($request, $token);

        self::assertSame('/api/login/success', $response->headers->get('Location'));
    }

    /**
     * @return void
     */
    public function testApiRequestWithoutAUserTokenThrowsAuthenticationRequired(): void
    {
        $handler = new ApiAuthenticationSuccessHandler(
            $this->createStub(SecurityServiceInterface::class),
            new LoginSuccessResponder('http://frontend.test'),
            $this->createStub(UrlGeneratorInterface::class),
        );
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn(null);

        try {
            $handler->onAuthenticationSuccess(Request::create('/api/login', 'POST'), $token);
            self::fail('Expected exception was not thrown.');
        } catch (UserNotAuthenticatedException $exception) {
            self::assertSame('AUTHENTICATION_REQUIRED', $exception->getErrorCode());
            self::assertSame(401, $exception->getStatusCode());
        }
    }
}
