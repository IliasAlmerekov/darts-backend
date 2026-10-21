<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Player;

use App\Dto\PlayerProfileResponseDto;
use App\Entity\User;

/**
 * Builds a player's own Profile.
 */
interface PlayerProfileServiceInterface
{
    /**
     * @param User $player a persisted player
     *
     * @return PlayerProfileResponseDto
     */
    public function getProfile(User $player): PlayerProfileResponseDto;
}
