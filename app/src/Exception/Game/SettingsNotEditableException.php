<?php

declare(strict_types=1);

namespace App\Exception\Game;

use App\Exception\ApiHttpException;
use Symfony\Component\HttpFoundation\Response;

final class SettingsNotEditableException extends ApiHttpException
{
    public function __construct()
    {
        parent::__construct(
            'SETTINGS_NOT_EDITABLE',
            Response::HTTP_CONFLICT,
            'Settings can only be changed while the game is in the lobby or started.'
        );
    }
}

