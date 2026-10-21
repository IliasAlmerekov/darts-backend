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
 * Email and username are validated and saved without surrounding whitespace. The password
 * is validated and hashed exactly as sent. A missing field stays uninitialized, which the
 * validator reads as null, so it fails NotBlank. The properties have no default value
 * because Nelmio then skips the Length limits in the API docs.
 */
final class RegistrationRequest
{
    #[OA\Property(
        description: 'Nur Adressen der Domain `db-n.com` werden angenommen, ohne Beachtung der Groß- und Kleinschreibung und nach Entfernen der Leerzeichen am Rand. Subdomains und andere Domains ergeben 400 mit `errors.email`.',
        format: 'email',
        example: 'alice@db-n.com',
    )]
    #[Assert\Sequentially([
        new Assert\NotBlank(message: 'Please enter an email address', normalizer: 'trim'),
        new Assert\Email(message: 'Please enter a valid email address', normalizer: 'trim'),
        new Assert\Regex(pattern: '/@db-n\.com$/i', message: 'Please register with your @db-n.com email address', normalizer: 'trim'),
    ])]
    public string $email;

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
    public string $username;

    #[OA\Property(format: 'password', example: 'secret123')]
    #[Assert\Sequentially([
        new Assert\NotBlank(message: 'Please enter a password'),
        new Assert\Length(
            min: 6,
            max: 4096,
            minMessage: 'Your password should be at least {{ limit }} characters long.',
        ),
    ])]
    public string $plainPassword;
}
