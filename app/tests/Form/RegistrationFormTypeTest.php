<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Entity\User;
use App\Form\RegistrationFormType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Validator\Validation;

/**
 * Builds the real form with the real validator, so constraint construction and messages are covered.
 */
final class RegistrationFormTypeTest extends TestCase
{
    private FormFactoryInterface $formFactory;

    #[\Override]
    protected function setUp(): void
    {
        $this->formFactory = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->getFormFactory();
    }

    public function testValidDataIsAccepted(): void
    {
        $form = $this->formFactory->create(RegistrationFormType::class, new User());
        $form->submit([
            'email' => 'player@example.com',
            'username' => 'player',
            'plainPassword' => 'secret123',
        ]);

        self::assertTrue($form->isValid());
    }

    public function testBlankFieldsReportConfiguredMessages(): void
    {
        $form = $this->formFactory->create(RegistrationFormType::class, new User());
        $form->submit([
            'email' => '',
            'username' => '',
            'plainPassword' => '',
        ]);

        self::assertFalse($form->isValid());
        self::assertSame('Please enter an email address', $form->get('email')->getErrors()[0]?->getMessage());
        self::assertSame('Please enter a username', $form->get('username')->getErrors()[0]?->getMessage());
        self::assertSame('Please enter a password', $form->get('plainPassword')->getErrors()[0]?->getMessage());
    }

    public function testInvalidEmailReportsConfiguredMessage(): void
    {
        $form = $this->formFactory->create(RegistrationFormType::class, new User());
        $form->submit([
            'email' => 'not-an-email',
            'username' => 'player',
            'plainPassword' => 'secret123',
        ]);

        self::assertSame('Please enter a valid email address', $form->get('email')->getErrors()[0]?->getMessage());
    }
}
