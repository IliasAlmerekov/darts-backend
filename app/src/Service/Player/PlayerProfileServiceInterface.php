<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Player;

use App\Dto\PlayerProfileResponseDto;
use App\Dto\PlayerPublicProfileResponseDto;
use App\Entity\User;

/**
 * Builds a player's own Profile and the Public profile other players see.
 */
interface PlayerProfileServiceInterface
{
    /**
     * @param User $player a persisted player
     *
     * @return PlayerProfileResponseDto
     */
    public function getProfile(User $player): PlayerProfileResponseDto;

    /**
     * The name and Profile statistics of a registered player, without their recent games,
     * registration date or email.
     *
     * @param User $player a persisted registered player
     *
     * @return PlayerPublicProfileResponseDto
     */
    public function getPublicProfile(User $player): PlayerPublicProfileResponseDto;
}
