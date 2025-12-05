<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\RegistrationController;
use App\Entity\User;
use App\Form\RegistrationFormType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormErrorIterator;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class RegistrationControllerTest extends TestCase
{
    private RegistrationController $controller;
    private ContainerInterface&MockObject $container;

    protected function setUp(): void
    {
        $this->controller = new RegistrationController();
        $this->container = $this->createMock(ContainerInterface::class);
        $this->controller->setContainer($this->container);
    }

    /**
     * Test: Erfolgreiche Registrierung
     */
    public function testRegisterSuccessfullyCreatesUser(): void
    {
        $jsonContent = json_encode([
            'username' => 'newuser',
            'email' => 'newuser@example.com',
            'plainPassword' => 'SecurePass123!'
        ]);

        $request = Request::create(
            uri: '/api/register',
            method: 'POST',
            parameters: [],
            cookies: [],
            files: [],
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $jsonContent
        );

        // Mock Form
        $form = $this->createMock(FormInterface::class);
        $form->method('submit')->willReturnSelf();
        $form->method('isSubmitted')->willReturn(true);
        $form->method('isValid')->willReturn(true);

        // Mock Form Fields
        $plainPasswordField = $this->createMock(FormInterface::class);
        $plainPasswordField->method('getData')->willReturn('SecurePass123!');

        $usernameField = $this->createMock(FormInterface::class);
        $usernameField->method('getData')->willReturn('newuser');

        $emailField = $this->createMock(FormInterface::class);
        $emailField->method('getData')->willReturn('newuser@example.com');

        $form->method('get')->willReturnCallback(function($field) use ($plainPasswordField, $usernameField, $emailField) {
            return match($field) {
                'plainPassword' => $plainPasswordField,
                'username' => $usernameField,
                'email' => $emailField,
                default => null
            };
        });

        // Mock FormFactory
        $formFactory = $this->createMock(FormFactoryInterface::class);
        $formFactory->expects($this->once())
            ->method('create')
            ->with(RegistrationFormType::class, $this->isInstanceOf(User::class))
            ->willReturn($form);

        // Mock PasswordHasher
        $passwordHasher = $this->createMock(UserPasswordHasherInterface::class);
        $passwordHasher->expects($this->once())
            ->method('hashPassword')
            ->with($this->isInstanceOf(User::class), 'SecurePass123!')
            ->willReturn('$hashed$password$');

        // Mock EntityManager
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function($user) {
                return $user instanceof User
                    && $user->getUsername() === 'newuser'
                    && $user->getEmail() === 'newuser@example.com'
                    && $user->getPassword() === '$hashed$password$'
                    && in_array('ROLE_PLAYER', $user->getRoles());
            }));
        $entityManager->expects($this->once())->method('flush');

        $this->container->method('has')->willReturnCallback(function($service) {
            return $service === 'form.factory';
        });

        $this->container->method('get')->willReturnCallback(function($service) use ($formFactory) {
            return match($service) {
                'form.factory' => $formFactory,
                default => null
            };
        });

        $response = $this->controller->register(
            $request,
            $passwordHasher,
            $entityManager
        );

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(Response::HTTP_CREATED, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertEquals('Registrierung erfolgreich', $data['message']);
        $this->assertEquals('/', $data['redirect']);
    }

    /**
     * Test: Ungültiger JSON -> 400 Bad Request
     */
    public function testRegisterReturns400FormValidationErrors(): void
    {
        $jsonContent = json_encode([
            'username' => 'a',
            'email' => 'invalid-email',
            'plainPassword' => '123'
        ]);

        $request = Request::create(
            uri: '/api/register',
            method: 'POST',
            parameters: [],
            cookies: [],
            files: [],
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $jsonContent
        );

        // Mock Form mit Fehlern
        $form = $this->createMock(FormInterface::class);
        $form->method('submit')->willReturnSelf();
        $form->method('isSubmitted')->willReturn(true);
        $form->method('isValid')->willReturn(false);

        $usernameField = $this->createMock(FormInterface::class);
        $usernameField->method('getName')->willReturn('username');

        $formError = new FormError('Username ist zu kurz');

        $formErrorMock = $this->createMock(FormError::class);
        $formErrorMock->method('getMessage')->willReturn('Username ist zu kurz');
        $formErrorMock->method('getOrigin')->willReturn($usernameField);

        $errorIterator = new FormErrorIterator($form, [$formErrorMock]);
        $form->method('getErrors')->willReturn($errorIterator);

        // Mock FormFactory
        $formFactory = $this->createMock(FormFactoryInterface::class);
        $formFactory->method('create')->willReturn($form);

        $passwordHasher = $this->createMock(UserPasswordHasherInterface::class);
        $passwordHasher->expects($this->never())->method('hashPassword');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('persist');

        $this->container->method('has')->willReturnCallback(function($service) {
            return $service === 'form.factory';
        });

        $this->container->method('get')->willReturnCallback(function($service) use ($formFactory) {
            return match($service) {
                'form.factory' => $formFactory,
                default => null
            };
        });

        $response = $this->controller->register(
            $request,
            $passwordHasher,
            $entityManager
        );

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertArrayHasKey('errors', $data);
    }
}
