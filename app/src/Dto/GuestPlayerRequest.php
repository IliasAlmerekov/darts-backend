<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Payload DTO for adding a guest player.
 */
final class GuestPlayerRequest
{
    #[Assert\NotBlank(message: 'Please enter a username')]
    #[Assert\Length(max: 30, maxMessage: 'Your username should be at most {{ limit }} characters')]
    #[Assert\Regex(
        pattern: '/^(?=(?:.*\p{L}){3,}).+$/u',
        message: 'Username must contain at least 3 letters'
    )]
    public ?string $username = null;
}
