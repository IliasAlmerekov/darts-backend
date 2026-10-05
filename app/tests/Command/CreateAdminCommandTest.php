<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\CreateAdminCommand;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Repository\UserRepositoryInterface;
use Closure;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class CreateAdminCommandTest extends KernelTestCase
{
    private const string PASSWORD = 'correct-horse-battery';

    private EntityManagerInterface $entityManager;
    private UserRepository $users;
    private CommandTester $tester;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->users = $container->get(UserRepository::class);
        $this->tester = $this->createTester(static fn (): bool => true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager->close();
    }

    public function testNonInteractiveRunIsRefused(): void
    {
        $status = $this->tester->execute([], ['interactive' => false]);

        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString('interactive terminal', $this->tester->getDisplay());
        self::assertNull($this->users->findOneBy(['email' => 'admin@example.test']));
    }

    public function testInputWithoutTerminalIsRefused(): void
    {
        $tester = $this->createTester(static fn (): bool => false);
        $tester->setInputs(['admin@example.test', 'admin', self::PASSWORD, self::PASSWORD]);

        $status = $tester->execute([]);

        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString('interactive terminal', $tester->getDisplay());
        self::assertStringNotContainsString('Email', $tester->getDisplay());
        self::assertNull($this->users->findOneBy(['email' => 'admin@example.test']));
    }

    public function testCommandIsRegistered(): void
    {
        $application = new Application(self::$kernel ?? self::bootKernel());

        $command = $application->find('app:create-admin');
        if ($command instanceof LazyCommand) {
            $command = $command->getCommand();
        }

        self::assertInstanceOf(CreateAdminCommand::class, $command);
    }

    public function testExistingAdministratorBlocksAnotherOne(): void
    {
        $this->persistUser('owner', 'owner@example.test', ['ROLE_ADMIN']);
        $this->tester->setInputs(['admin@example.test', 'admin', self::PASSWORD, self::PASSWORD]);

        $status = $this->tester->execute([]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('administrator already exists', $this->tester->getDisplay());
        self::assertStringNotContainsString('Email', $this->tester->getDisplay());
        self::assertNull($this->users->findOneBy(['email' => 'admin@example.test']));
    }

    public function testDuplicateEmailIsRefused(): void
    {
        $this->persistUser('player', 'taken@example.test', ['ROLE_PLAYER']);
        $this->tester->setInputs(['taken@example.test', 'newadmin', self::PASSWORD, self::PASSWORD]);

        $status = $this->tester->execute([]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('already in use', $this->tester->getDisplay());
        self::assertNull($this->users->findOneBy(['username' => 'newadmin']));
    }

    public function testDuplicateUsernameIsRefused(): void
    {
        $this->persistUser('taken', 'player@example.test', ['ROLE_PLAYER']);
        $this->tester->setInputs(['admin@example.test', 'taken', self::PASSWORD, self::PASSWORD]);

        $status = $this->tester->execute([]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('already in use', $this->tester->getDisplay());
        self::assertNull($this->users->findOneBy(['email' => 'admin@example.test']));
    }

    public function testMismatchedPasswordsCreateNothing(): void
    {
        $this->tester->setInputs(['admin@example.test', 'admin', self::PASSWORD, 'another-long-password']);

        $status = $this->tester->execute([]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('do not match', $this->tester->getDisplay());
        self::assertStringNotContainsString(self::PASSWORD, $this->tester->getDisplay());
        self::assertStringNotContainsString('another-long-password', $this->tester->getDisplay());
        self::assertNull($this->users->findOneBy(['email' => 'admin@example.test']));
    }

    public function testInvalidAnswersAreAskedAgain(): void
    {
        $this->tester->setInputs([
            'not-an-email',
            'admin@example.test',
            'ab',
            'admin',
            'too-short',
            self::PASSWORD,
            self::PASSWORD,
        ]);

        $status = $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringNotContainsString('too-short', $this->tester->getDisplay());
        self::assertNotNull($this->users->findOneBy(['email' => 'admin@example.test']));
    }

    public function testAdministratorIsCreatedWithHashedPassword(): void
    {
        $this->tester->setInputs(['admin@example.test', 'admin', self::PASSWORD, self::PASSWORD]);

        $status = $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringNotContainsString(self::PASSWORD, $this->tester->getDisplay());

        $this->entityManager->clear();
        $admin = $this->users->findOneBy(['email' => 'admin@example.test']);
        self::assertInstanceOf(User::class, $admin);
        self::assertSame('admin', $admin->getUsername());
        self::assertSame('admin', $admin->getDisplayNameRaw());
        self::assertSame(['ROLE_ADMIN'], $admin->getStoredRoles());
        self::assertFalse($admin->isGuest());
        self::assertNotSame(self::PASSWORD, $admin->getPassword());

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($admin, self::PASSWORD));
    }

    public function testSurroundingSpacesStayPartOfThePassword(): void
    {
        $password = '  spaced pass phrase  ';
        $this->tester->setInputs(['admin@example.test', 'admin', $password, $password]);

        self::assertSame(Command::SUCCESS, $this->tester->execute([]));

        $this->entityManager->clear();
        $admin = $this->users->findOneBy(['email' => 'admin@example.test']);
        self::assertInstanceOf(User::class, $admin);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($admin, $password));
        self::assertFalse($hasher->isPasswordValid($admin, trim($password)));
    }

    /**
     * @param Closure(): bool $stdinIsTerminal
     */
    private function createTester(Closure $stdinIsTerminal): CommandTester
    {
        $container = static::getContainer();

        return new CommandTester(new CreateAdminCommand(
            $container->get(UserRepositoryInterface::class),
            $container->get(EntityManagerInterface::class),
            $container->get(UserPasswordHasherInterface::class),
            $container->get(ValidatorInterface::class),
            $stdinIsTerminal,
        ));
    }

    /**
     * @param list<string> $roles
     */
    private function persistUser(string $username, string $email, array $roles): void
    {
        $user = (new User())
            ->setUsername($username)
            ->setEmail($email)
            ->setRoles($roles)
            ->setPassword('not-a-real-hash');

        $this->entityManager->persist($user);
        $this->entityManager->flush();
    }
}
