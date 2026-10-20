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
 * One finished game in a player's Profile, with the player's own placement and round average.
 *
 * @psalm-suppress PossiblyUnusedProperty Used via Symfony Serializer
 */
final class PlayerProfileGameDto
{
    /**
     * @param int                               $gameId
     * @param string|null                       $finishedAt        ATOM timestamp, null when the game has none
     * @param int|null                          $placement         the player's own placement
     * @param float                             $roundAverage      the player's own round average, as in the game summary
     * @param int                               $participantsCount size of participants
     * @param list<PlayerProfileParticipantDto> $participants      ordered by placement
     */
    public function __construct(
        public int $gameId,
        public ?string $finishedAt,
        public ?int $placement,
        public float $roundAverage,
        public int $participantsCount,
        public array $participants,
    ) {
    }
}
