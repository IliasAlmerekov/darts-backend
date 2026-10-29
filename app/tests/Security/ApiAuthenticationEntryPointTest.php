<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\ApiAuthenticationEntryPoint;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\RememberMeToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\InsufficientAuthenticationException;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class ApiAuthenticationEntryPointTest extends TestCase
{
    public function testApiAndNonApiResponsesDependOnTheExceptionAndToken(): void
    {
        $entryPoint = new ApiAuthenticationEntryPoint($this->createStub(UrlGeneratorInterface::class));
        $remembered = new RememberMeToken(new InMemoryUser('player', null, ['ROLE_PLAYER']), 'main');
        $otherToken = new UsernamePasswordToken(new InMemoryUser('player', null), 'main');
        $wrongException = new AuthenticationException();
        $wrongException->setToken($remembered);
        $wrongToken = new InsufficientAuthenticationException();
        $wrongToken->setToken($otherToken);

        $rememberedDenial = new InsufficientAuthenticationException();
        $rememberedDenial->setToken($remembered);
        $response = $entryPoint->start(Request::create('/api/room/create'), $rememberedDenial);
        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertSame(['success' => false, 'error' => 'ACCESS_DENIED', 'message' => 'Forbidden'], json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR));

        foreach ([null, new InsufficientAuthenticationException(), $wrongException, $wrongToken] as $exception) {
            $response = $entryPoint->start(Request::create('/api/room/create'), $exception);
            self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
            self::assertSame(['success' => false, 'error' => 'AUTHENTICATION_REQUIRED', 'message' => 'Unauthorized'], json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR));
        }

        $generator = $this->createStub(UrlGeneratorInterface::class);
        $generator->method('generate')->willReturn('/login');
        $exception = new InsufficientAuthenticationException();
        $exception->setToken(new RememberMeToken(new InMemoryUser('player', null), 'main'));

        $response = (new ApiAuthenticationEntryPoint($generator))->start(Request::create('/page'), $exception);

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('/login', $response->headers->get('Location'));
    }
}
