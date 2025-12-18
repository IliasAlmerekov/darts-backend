<?php

declare(strict_types=1);

namespace App\Exception\Game;

use App\Exception\ApiHttpException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown when attempting to update settings in an invalid game state.
 */
final class SettingsNotEditableException extends ApiHttpException
{
    /**
     * @return void
     */
    public function __construct()
    {
        parent::__construct(
            'SETTINGS_NOT_EDITABLE',
            Response::HTTP_CONFLICT,
            'Settings can only be changed while the game is in the lobby or started.'
        );
    }
}
