<?php

declare(strict_types=1);

namespace App\Exception\Game;

use App\Exception\ApiHttpException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown when a settings update request contains no changes.
 */
final class NoSettingsProvidedException extends ApiHttpException
{
    /**
     * @return void
     */
    public function __construct()
    {
        parent::__construct(
            'NO_SETTINGS_PROVIDED',
            Response::HTTP_BAD_REQUEST,
            'No settings provided to update.'
        );
    }
}
