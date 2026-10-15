<?php

declare(strict_types=1);

namespace App\Tests\Service\Registration;

use App\Entity\User;
use App\Form\RegistrationFormType;
use App\Service\Registration\RegistrationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormErrorIterator;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;

#[AllowMockObjectsWithoutExpectations]
final class RegistrationServiceTest extends TestCase
{
    private FormFactoryInterface&MockObject $formFactory;
    private UserPasswordHasherInterface&MockObject $passwordHasher;
    private EntityManagerInterface&MockObject $entityManager;
    private Security&MockObject $security;
    private RegistrationService $service;

    protected function setUp(): void
    {
        $this->formFactory = $this->createMock(FormFactoryInterface::class);
        $this->passwordHasher = $this->createMock(UserPasswordHasherInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->security = $this->createMock(Security::class);

        $this->service = new RegistrationService(
            $this->formFactory,
            $this->passwordHasher,
            $this->entityManager,
            $this->security,
        );
    }

    public function testRegisterReturnsErrorsWhenFormInvalid(): void
    {
        $data = ['username' => 'john'];
        $form = $this->createMock(FormInterface::class);
        $field = $this->createMock(FormInterface::class);
        $field->method('getName')->willReturn('username');

        $error = new FormError('invalid username');
        $error->setOrigin($field);
        $errors = new FormErrorIterator($form, [$error]);

        $form->expects(self::once())->method('submit')->with($data);
        $form->method('isSubmitted')->willReturn(true);
        $form->method('isValid')->willReturn(false);
        $form->method('getErrors')->with(true)->willReturn($errors);

        $this->formFactory
            ->expects(self::once())
            ->method('create')
            ->with(RegistrationFormType::class, self::isInstanceOf(User::class))
            ->willReturn($form);

        $this->security->expects(self::never())->method('login');

        $result = $this->service->register($data);

        self::assertIsArray($result);
        self::assertFalse($result['success']);
        self::assertSame(Response::HTTP_BAD_REQUEST, $result['status']);
        self::assertArrayHasKey('errors', $result);
        self::assertSame(['invalid username'], $result['errors']['username']);
    }

    public function testRegisterCreatesUserAndLogsThemInWhenFormValid(): void
    {
        $data = [
            'username' => 'alice',
            'email' => 'alice@test.dev',
            'plainPassword' => 'secret',
        ];

        $form = $this->createMock(FormInterface::class);
        $plainPasswordField = $this->createMock(FormInterface::class);
        $plainPasswordField->method('getData')->willReturn('secret');
        $usernameField = $this->createMock(FormInterface::class);
        $usernameField->method('getData')->willReturn('alice');
        $emailField = $this->createMock(FormInterface::class);
        $emailField->method('getData')->willReturn('alice@test.dev');

        $form->expects(self::once())->method('submit')->with($data);
        $form->method('isSubmitted')->willReturn(true);
        $form->method('isValid')->willReturn(true);
        $form->method('get')->willReturnMap([
            ['plainPassword', $plainPasswordField],
            ['username', $usernameField],
            ['email', $emailField],
        ]);

        $persistedUser = null;
        $this->formFactory
            ->expects(self::once())
            ->method('create')
            ->with(RegistrationFormType::class, self::isInstanceOf(User::class))
            ->willReturn($form);

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

        $result = $this->service->register($data);

        self::assertSame($loginResponse, $result);
        self::assertSame(Response::HTTP_CREATED, $loginResponse->getStatusCode());
        self::assertInstanceOf(User::class, $persistedUser);
        self::assertSame('alice', $persistedUser->getUsername());
        self::assertSame('alice@test.dev', $persistedUser->getEmail());
        self::assertSame('hashed_secret', $persistedUser->getPassword());
        self::assertContains('ROLE_PLAYER', $persistedUser->getRoles());
    }

    public function testRegisterKeepsAFailedLoginStatus(): void
    {
        $this->givenValidForm(['username' => 'bob', 'email' => 'bob@test.dev', 'plainPassword' => 'secret']);
        $loginResponse = new JsonResponse(['success' => false, 'redirect' => '/start'], Response::HTTP_BAD_REQUEST);
        $this->security->method('login')->willReturn($loginResponse);

        $result = $this->service->register(['username' => 'bob', 'email' => 'bob@test.dev', 'plainPassword' => 'secret']);

        self::assertSame($loginResponse, $result);
        self::assertSame(Response::HTTP_BAD_REQUEST, $loginResponse->getStatusCode());
    }

    /**
     * @param array{username: string, email: string, plainPassword: string} $data
     */
    private function givenValidForm(array $data): void
    {
        $form = $this->createMock(FormInterface::class);
        $form->method('isSubmitted')->willReturn(true);
        $form->method('isValid')->willReturn(true);
        $fields = [];
        foreach ($data as $name => $value) {
            $field = $this->createMock(FormInterface::class);
            $field->method('getData')->willReturn($value);
            $fields[] = [$name, $field];
        }
        $form->method('get')->willReturnMap($fields);
        $this->formFactory->method('create')->willReturn($form);
        $this->passwordHasher->method('hashPassword')->willReturn('hashed');
    }
}
