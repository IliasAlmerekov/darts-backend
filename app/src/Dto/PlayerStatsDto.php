<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Dto;

use OpenApi\Attributes as OA;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * This class is used to serialize player stats
 */
final class PlayerStatsDto
{
    #[Groups(['stats:read'])]
    public int $playerId;
    #[Groups(['stats:read'])]
    public string $name;
    #[Groups(['stats:read'])]
    public int $gamesPlayed;
    #[Groups(['stats:read'])]
    #[OA\Property(description: 'Drei-Dart-Durchschnitt über alle Aufnahmen beendeter Spiele: Punkte geteilt durch geworfene Darts, mal drei. Eine Aufnahme mit Bust zählt 0 Punkte und 3 Darts.')]
    public float $scoreAverage;

    /**
     * @param int    $playerId
     * @param string $name
     * @param int    $gamesPlayed
     * @param float  $scoreAverage
     */
    public function __construct(int $playerId, string $name, int $gamesPlayed, float $scoreAverage)
    {
        $this->playerId = $playerId;
        $this->name = $name;
        $this->gamesPlayed = $gamesPlayed;
        $this->scoreAverage = $scoreAverage;
    }
}
