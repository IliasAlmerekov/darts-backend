<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepositoryInterface;
use Closure;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Creates the first administrator account from an interactive terminal.
 *
 * The password is read only through hidden prompts: there is no argument or option for it,
 * so it never lands in shell history, process lists, or container logs.
 *
 * @psalm-suppress UnusedClass Reason: registered through Symfony command autoconfiguration.
 */
#[AsCommand(name: 'app:create-admin', description: 'Create the first administrator interactively.')]
final class CreateAdminCommand extends Command
{
    private const string ADMIN_ROLE = 'ROLE_ADMIN';
    private const int MIN_PASSWORD_LENGTH = 12;
    private const int MAX_PASSWORD_LENGTH = 4096;

    /** @var Closure(): bool */
    private readonly Closure $stdinIsTerminal;

    /**
     * @param UserRepositoryInterface     $users
     * @param EntityManagerInterface      $entityManager
     * @param UserPasswordHasherInterface $passwordHasher
     * @param ValidatorInterface          $validator
     * @param (Closure(): bool)|null      $stdinIsTerminal Replaces the STDIN check in tests.
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ValidatorInterface $validator,
        ?Closure $stdinIsTerminal = null,
    ) {
        parent::__construct();
        $this->stdinIsTerminal = $stdinIsTerminal ?? static fn (): bool => stream_isatty(STDIN);
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return int
     */
    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Symfony only turns interaction off for --no-interaction. A pipe or heredoc would
        // still feed the prompts, so require a real terminal as well.
        if (!$input->isInteractive() || !($this->stdinIsTerminal)()) {
            $io->error([
                'Run this command in an interactive terminal (docker compose run with a TTY).',
                'Passwords cannot be passed as arguments or piped in.',
            ]);

            return Command::INVALID;
        }

        if ($this->users->hasUserWithRole(self::ADMIN_ROLE)) {
            $io->error('An administrator already exists. No account was created.');

            return Command::FAILURE;
        }

        $email = $this->askValidated($io, 'Email', [new NotBlank(), new Email(), new Length(max: 180)]);
        $username = $this->askValidated($io, 'Username', [new NotBlank(), new Length(min: 3, max: 30)]);

        if ([] !== $this->users->findBy(['email' => $email], null, 1)
            || null !== $this->users->findOneByUsername($username)
        ) {
            $io->error('That email or username is already in use. No account was created.');

            return Command::FAILURE;
        }

        $password = $this->askHidden($io, 'Password', true);
        $repeated = $this->askHidden($io, 'Repeat password', false);

        if (!hash_equals($password, $repeated)) {
            $io->error('The passwords do not match. No account was created.');

            return Command::FAILURE;
        }

        $user = (new User())
            ->setEmail($email)
            ->setUsername($username)
            ->setRoles([self::ADMIN_ROLE]);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));

        try {
            $this->entityManager->persist($user);
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            $io->error('That email or username is already in use. No account was created.');

            return Command::FAILURE;
        }

        $io->success(sprintf('Administrator "%s" created.', $username));

        return Command::SUCCESS;
    }

    /**
     * @param SymfonyStyle     $io
     * @param string           $label
     * @param list<Constraint> $constraints
     *
     * @return string
     */
    private function askValidated(SymfonyStyle $io, string $label, array $constraints): string
    {
        $answer = $io->ask($label, null, function (mixed $value) use ($constraints): string {
            $value = is_string($value) ? trim($value) : '';
            $this->assertValid($value, $constraints);

            return $value;
        });

        return is_string($answer) ? $answer : '';
    }

    /**
     * Asks without echoing. With the fallback disabled, Symfony refuses instead of showing the input
     * when the terminal cannot hide it.
     *
     * @param SymfonyStyle $io
     * @param string       $label
     * @param bool         $validateStrength
     *
     * @return string
     */
    private function askHidden(SymfonyStyle $io, string $label, bool $validateStrength): string
    {
        $question = new Question($label);
        $question->setHidden(true);
        $question->setHiddenFallback(false);
        // Keep spaces that belong to the password; drop only the line ending the terminal sends.
        $question->setTrimmable(false);
        $question->setNormalizer(static fn (mixed $value): string => is_string($value) ? rtrim($value, "\r\n") : '');
        if ($validateStrength) {
            $question->setValidator(function (mixed $value): string {
                $value = is_string($value) ? $value : '';
                $this->assertValid($value, [
                    new NotBlank(),
                    new Length(min: self::MIN_PASSWORD_LENGTH, max: self::MAX_PASSWORD_LENGTH),
                ]);

                return $value;
            });
        }

        $answer = $io->askQuestion($question);

        return is_string($answer) ? $answer : '';
    }

    /**
     * @param string           $value
     * @param list<Constraint> $constraints
     *
     * @return void
     *
     * @throws InvalidArgumentException when the value breaks a constraint
     */
    private function assertValid(string $value, array $constraints): void
    {
        $violations = $this->validator->validate($value, $constraints);
        if (0 < count($violations)) {
            throw new InvalidArgumentException((string) $violations->get(0)->getMessage());
        }
    }
}
