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
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AllowMockObjectsWithoutExpectations]
final class RegistrationServiceTest extends TestCase
{
    private UserPasswordHasherInterface&MockObject $passwordHasher;
    private EntityManagerInterface&MockObject $entityManager;
    private RegistrationService $service;

    protected function setUp(): void
    {
        $this->passwordHasher = $this->createMock(UserPasswordHasherInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $this->service = new RegistrationService(
            $this->passwordHasher,
            $this->entityManager,
        );
    }

    public function testRegisterPersistsAndReturnsThePlayer(): void
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

        $result = $this->service->register($request);

        self::assertSame($persistedUser, $result);
        self::assertInstanceOf(User::class, $persistedUser);
        self::assertSame('alice', $persistedUser->getUsername());
        self::assertSame('alice@db-n.com', $persistedUser->getEmail());
        self::assertSame('hashed_secret12', $persistedUser->getPassword());
        self::assertContains('ROLE_PLAYER', $persistedUser->getRoles());
    }

    public function testUniqueIndexViolationOnFlushBecomesAccountExists(): void
    {
        $this->passwordHasher->method('hashPassword')->willReturn('hashed');
        $violation = new UniqueConstraintViolationException($this->createStub(DriverException::class), null);
        $this->entityManager->method('flush')->willThrowException($violation);

        try {
            $this->service->register($this->request('carol', 'carol@db-n.com', 'secret12'));
            self::fail('Expected RegistrationAccountExistsException.');
        } catch (RegistrationAccountExistsException $exception) {
            self::assertSame(Response::HTTP_CONFLICT, $exception->getStatusCode());
            self::assertSame('REGISTRATION_ACCOUNT_EXISTS', $exception->getErrorCode());
            self::assertSame('An account with these details already exists.', $exception->getMessage());
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
