<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Player;

use App\Dto\PlayerProfileResponseDto;

/**
 * Builds a player's own Profile.
 */
interface PlayerProfileServiceInterface
{
    /**
     * @param int $playerId
     *
     * @return PlayerProfileResponseDto
     */
    public function getProfile(int $playerId): PlayerProfileResponseDto;
}
