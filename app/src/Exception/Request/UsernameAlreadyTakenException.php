<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Exception\Request;

use RuntimeException;

/**
 * Thrown when a username is already taken.
 */
final class UsernameAlreadyTakenException extends RuntimeException
{
    /**
     * @param string       $username
     * @param list<string> $suggestions
     */
    public function __construct(private readonly string $username, private readonly array $suggestions)
    {
        parent::__construct('Username already taken');
    }

    /**
     * @return string
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function getUsername(): string
    {
        return $this->username;
    }

    /**
     * @return list<string>
     */
    public function getSuggestions(): array
    {
        return $this->suggestions;
    }
}
