<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Annotation\Groups;

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
