<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Central list of API error codes.
 */
enum ErrorCode: string
{
    case GameInvalidPlayerCount = 'GAME_INVALID_PLAYER_COUNT';
    case GameNotFound = 'GAME_NOT_FOUND';
    case GameInvalidOutMode = 'GAME_INVALID_OUT_MODE';
    case GameInvalidStartScore = 'GAME_INVALID_START_SCORE';
    case GameNoSettingsProvided = 'GAME_NO_SETTINGS_PROVIDED';
    case GamePlayerThrowsLimitReached = 'GAME_PLAYER_THROWS_LIMIT_REACHED';
    case GamePlayerNotFound = 'GAME_PLAYER_NOT_FOUND';
    case GamePlayerPositionsCountMismatch = 'GAME_PLAYER_POSITIONS_COUNT_MISMATCH';
    case GameSettingsNotEditable = 'GAME_SETTINGS_NOT_EDITABLE';
    case GameStartScoreChangeNotAllowed = 'GAME_START_SCORE_CHANGE_NOT_ALLOWED';
    case GameJoinNotAllowed = 'GAME_JOIN_NOT_ALLOWED';
    case GameIdMissing = 'GAME_ID_MISSING';
    case RequestInvalidJsonBody = 'REQUEST_INVALID_JSON_BODY';
    case RequestPlayerIdRequired = 'REQUEST_PLAYER_ID_REQUIRED';
    case SecurityUserNotAuthenticated = 'SECURITY_USER_NOT_AUTHENTICATED';
    case SecurityAccessDenied = 'SECURITY_ACCESS_DENIED';
}
