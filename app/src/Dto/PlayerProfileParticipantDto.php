<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Dto;

/**
 * @psalm-immutable
 * A participant of a finished game in a player's Profile; carries no numbers beyond the placement.
 *
 * @psalm-suppress PossiblyUnusedProperty Used via Symfony Serializer
 */
final class PlayerProfileParticipantDto
{
    /**
     * @param int|null    $playerId    user id of a player, null for a guest
     * @param string|null $displayName the participant's display name in that game
     * @param int|null    $placement   rank in the finished game, 1 is the winner
     * @param bool        $isSelf      true only for the requesting player
     */
    public function __construct(
        public ?int $playerId,
        public ?string $displayName,
        public ?int $placement,
        public bool $isSelf,
    ) {
    }
}
