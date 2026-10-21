<?php

declare(strict_types=1);

namespace App\Tests\Service\Registration;

use App\Dto\RegistrationRequest;
use App\Entity\User;
use App\Exception\Registration\RegistrationAccountExistsException;
use App\Service\Registration\RegistrationService;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;

#[AllowMockObjectsWithoutExpectations]
final class RegistrationServiceTest extends TestCase
{
    private UserPasswordHasherInterface&MockObject $passwordHasher;
    private EntityManagerInterface&MockObject $entityManager;
    private Security&MockObject $security;
    private RegistrationService $service;

    protected function setUp(): void
    {
        $this->passwordHasher = $this->createMock(UserPasswordHasherInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->security = $this->createMock(Security::class);

        $this->service = new RegistrationService(
            $this->passwordHasher,
            $this->entityManager,
            $this->security,
        );
    }

    public function testRegisterCreatesUserAndLogsThemIn(): void
    {
        $request = $this->request('alice', 'alice@db-n.com', 'secret12');

        $persistedUser = null;
        $this->passwordHasher
            ->expects(self::once())
            ->method('hashPassword')
            ->willReturnCallback(static function (User $user, string $plain): string {
                return 'hashed_' . $plain;
            });

        $this->entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(function (User $user) use (&$persistedUser): bool {
                $persistedUser = $user;

                return true;
            }));
        $this->entityManager->expects(self::once())->method('flush');

        $loginResponse = new JsonResponse(['success' => true, 'redirect' => 'http://localhost:5173/joined']);
        $this->security
            ->expects(self::once())
            ->method('login')
            ->with(
                self::callback(static function (User $user) use (&$persistedUser): bool {
                    return $user === $persistedUser;
                }),
                'form_login',
                'main',
                self::callback(static function (array $badges): bool {
                    return 1 === count($badges) && $badges[0] instanceof RememberMeBadge;
                }),
            )
            ->willReturn($loginResponse);

        $result = $this->service->register($request);

        self::assertSame($loginResponse, $result);
        self::assertSame(Response::HTTP_CREATED, $loginResponse->getStatusCode());
        self::assertInstanceOf(User::class, $persistedUser);
        self::assertSame('alice', $persistedUser->getUsername());
        self::assertSame('alice@db-n.com', $persistedUser->getEmail());
        self::assertSame('hashed_secret12', $persistedUser->getPassword());
        self::assertContains('ROLE_PLAYER', $persistedUser->getRoles());
    }

    public function testRegisterKeepsAFailedLoginStatus(): void
    {
        $this->passwordHasher->method('hashPassword')->willReturn('hashed');
        $loginResponse = new JsonResponse(['success' => false, 'redirect' => '/start'], Response::HTTP_BAD_REQUEST);
        $this->security->method('login')->willReturn($loginResponse);

        $result = $this->service->register($this->request('bob', 'bob@db-n.com', 'secret12'));

        self::assertSame($loginResponse, $result);
        self::assertSame(Response::HTTP_BAD_REQUEST, $loginResponse->getStatusCode());
    }

    public function testUniqueIndexViolationOnFlushBecomesAccountExistsWithoutLogin(): void
    {
        $this->passwordHasher->method('hashPassword')->willReturn('hashed');
        $violation = new UniqueConstraintViolationException($this->createStub(DriverException::class), null);
        $this->entityManager->method('flush')->willThrowException($violation);
        $this->security->expects(self::never())->method('login');

        try {
            $this->service->register($this->request('carol', 'carol@db-n.com', 'secret12'));
            self::fail('Expected RegistrationAccountExistsException.');
        } catch (RegistrationAccountExistsException $exception) {
            self::assertSame(Response::HTTP_CONFLICT, $exception->getStatusCode());
            self::assertSame('REGISTRATION_ACCOUNT_EXISTS', $exception->getErrorCode());
            self::assertSame('Ein Konto mit diesen Daten existiert bereits.', $exception->getMessage());
        }
    }

    private function request(string $username, string $email, string $plainPassword): RegistrationRequest
    {
        $request = new RegistrationRequest();
        $request->username = $username;
        $request->email = $email;
        $request->plainPassword = $plainPassword;

        return $request;
    }
}
