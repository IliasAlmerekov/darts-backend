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
    case GameRoomFull = 'GAME_ROOM_FULL';
    case GameInvalidOutMode = 'GAME_INVALID_OUT_MODE';
    case GameConflictingOutRules = 'GAME_CONFLICTING_OUT_RULES';
    case GameInvalidPlayerOrder = 'GAME_INVALID_PLAYER_ORDER';
    case GameInvalidStartScore = 'GAME_INVALID_START_SCORE';
    case GameNoSettingsProvided = 'GAME_NO_SETTINGS_PROVIDED';
    case GamePlayerThrowsLimitReached = 'GAME_PLAYER_THROWS_LIMIT_REACHED';
    case GameInvalidThrow = 'GAME_INVALID_THROW';
    case GamePlayerNotActive = 'GAME_PLAYER_NOT_ACTIVE';
    case GamePlayerNotFound = 'GAME_PLAYER_NOT_FOUND';
    case GamePlayerPositionsCountMismatch = 'GAME_PLAYER_POSITIONS_COUNT_MISMATCH';
    case GameSettingsNotEditable = 'GAME_SETTINGS_NOT_EDITABLE';
    case GameSeatsNotEditable = 'GAME_SEATS_NOT_EDITABLE';
    case GameStartNotAllowed = 'GAME_START_NOT_ALLOWED';
    case GameStartScoreChangeNotAllowed = 'GAME_START_SCORE_CHANGE_NOT_ALLOWED';
    case GameOutRuleChangeNotAllowed = 'GAME_OUT_RULE_CHANGE_NOT_ALLOWED';
    case GameAlreadyStarted = 'GAME_ALREADY_STARTED';
    case GameJoinNotAllowed = 'GAME_JOIN_NOT_ALLOWED';
    case GameNotFinished = 'GAME_NOT_FINISHED';
    case GameReopenNotAllowed = 'GAME_REOPEN_NOT_ALLOWED';
    case GameFinishNotAllowed = 'GAME_FINISH_NOT_ALLOWED';
    case GameAbortNotAllowed = 'GAME_ABORT_NOT_ALLOWED';
    case GameNotNewest = 'GAME_NOT_NEWEST';
    case GameThrowNotAllowed = 'GAME_THROW_NOT_ALLOWED';
    case PlayerNotFound = 'PLAYER_NOT_FOUND';
    case GameIdMissing = 'GAME_ID_MISSING';
    case RequestInvalidJsonBody = 'REQUEST_INVALID_JSON_BODY';
    case RequestConflictingSettingKeys = 'REQUEST_CONFLICTING_SETTING_KEYS';
    case RequestPlayerIdRequired = 'REQUEST_PLAYER_ID_REQUIRED';
    case RegistrationRateLimited = 'REGISTRATION_RATE_LIMITED';
    case RegistrationAccountExists = 'REGISTRATION_ACCOUNT_EXISTS';
    case SecurityUserNotAuthenticated = 'SECURITY_USER_NOT_AUTHENTICATED';
    case SecurityAccessDenied = 'SECURITY_ACCESS_DENIED';
}
