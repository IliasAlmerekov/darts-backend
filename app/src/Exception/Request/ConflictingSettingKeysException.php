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
 * Thrown when a request sends both spellings of one setting with different values.
 */
final class ConflictingSettingKeysException extends ApiHttpException
{
    /**
     * @param string $camelCaseKey
     * @param string $legacyKey
     */
    public function __construct(string $camelCaseKey, string $legacyKey)
    {
        parent::__construct(
            ErrorCode::RequestConflictingSettingKeys,
            Response::HTTP_BAD_REQUEST,
            sprintf('Die Schlüssel "%s" und "%s" haben unterschiedliche Werte. Bitte nur "%s" senden.', $camelCaseKey, $legacyKey, $camelCaseKey)
        );
    }
}
