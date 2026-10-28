<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Exception\Request;

use App\Exception\ApiHttpException;
use App\Exception\ErrorCode;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown when a username is already taken. The body carries the documented `suggestions` field.
 */
final class UsernameAlreadyTakenException extends ApiHttpException
{
    /**
     * @param string       $username
     * @param list<string> $suggestions
     */
    public function __construct(private readonly string $username, array $suggestions)
    {
        parent::__construct(
            ErrorCode::UsernameTaken,
            Response::HTTP_CONFLICT,
            'The name is already taken',
            extraFields: ['suggestions' => $suggestions],
        );
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
}
