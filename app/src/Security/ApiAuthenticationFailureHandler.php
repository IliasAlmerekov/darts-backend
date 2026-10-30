<?php

declare(strict_types=1);

namespace App\Security;

use App\Http\ApiRequest;

use App\Exception\ApiHttpException;
use App\Exception\Security\CrossOriginRequestException;
use App\Exception\Security\LoginInvalidCredentialsException;
use App\Exception\Security\LoginRateLimitedException;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;

/**
 * Returns JSON for API authentication failures instead of browser-style redirects.
 *
 * @psalm-suppress UnusedClass Wired via Symfony security configuration
 */
#[WithMonologChannel('security')]
final readonly class ApiAuthenticationFailureHandler implements AuthenticationFailureHandlerInterface
{
    /**
     * @param UrlGeneratorInterface $urlGenerator
     * @param LoggerInterface       $logger
     */
    public function __construct(private UrlGeneratorInterface $urlGenerator, private LoggerInterface $logger)
    {
    }

    /**
     * @param Request                 $request
     * @param AuthenticationException $exception
     *
     * @return Response
     */
    #[\Override]
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $this->logger->warning('Login failed.', [
            'exception_class' => $exception::class,
            'previous_class' => null === $exception->getPrevious() ? null : $exception->getPrevious()::class,
            'reason' => '' !== $exception->getMessage() ? $exception->getMessage() : $exception->getMessageKey(),
        ]);

        if (ApiRequest::matches($request)) {
            $error = $this->toApiException($exception);

            return new JsonResponse(
                [
                    'success' => false,
                    'error' => $error->getErrorCode(),
                    'message' => $error->getMessage(),
                ],
                $error->getStatusCode(),
                $error->getHeaders()
            );
        }

        return new RedirectResponse($this->urlGenerator->generate('app_login'));
    }

    /**
     * @param AuthenticationException $exception
     *
     * @return ApiHttpException
     */
    private function toApiException(AuthenticationException $exception): ApiHttpException
    {
        if ($exception instanceof InvalidCsrfTokenException) {
            return new CrossOriginRequestException();
        }

        if (!$exception instanceof TooManyLoginAttemptsAuthenticationException) {
            return new LoginInvalidCredentialsException();
        }

        $minutes = $exception->getMessageData()['%minutes%'] ?? null;

        return new LoginRateLimitedException(is_int($minutes) ? $minutes * 60 : null);
    }
}
