<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Dto;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Payload DTO for registering a player.
 *
 * Email and username are validated trimmed, and the password as sent, like the text and
 * password fields of the registration form this DTO replaced.
 */
final class RegistrationRequest
{
    #[OA\Property(format: 'email', example: 'alice@example.com')]
    #[Assert\NotBlank(message: 'Please enter an email address', normalizer: 'trim')]
    #[Assert\Email(message: 'Please enter a valid email address', normalizer: 'trim')]
    public ?string $email = null;

    #[OA\Property(example: 'alice')]
    #[Assert\Sequentially([
        new Assert\NotBlank(message: 'Please enter a username', normalizer: 'trim'),
        new Assert\Length(
            min: 3,
            max: 30,
            minMessage: 'Your username should be at least {{ limit }} characters',
            maxMessage: 'Your username should be at most {{ limit }} characters',
            normalizer: 'trim',
        ),
    ])]
    public ?string $username = null;

    #[OA\Property(format: 'password', example: 'secret123')]
    #[Assert\Sequentially([
        new Assert\NotBlank(message: 'Please enter a password'),
        new Assert\Length(
            min: 8,
            max: 4096,
            minMessage: 'Your password should be at least {{ limit }} characters long.',
        ),
    ])]
    public ?string $plainPassword = null;
}
