<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PlayerStatsRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass: PlayerStatsRepository::class)
 * This class represents the statistics of a player.
 */
#[ORM\Entity(repositoryClass: PlayerStatsRepository::class)]
class PlayerStats
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $playerId = null;
    #[ORM\Column(nullable: true)]
    private ?int $wins = null;
    #[ORM\Column]
    private ?int $winPercentage = null;
    #[ORM\Column(nullable: true)]
    private ?int $roundAverage = null;
    #[ORM\Column(nullable: true)]
    private ?int $highestCheckout = null;
    #[ORM\Column(nullable: true)]
    private ?int $gamesPlayed = null;
    public function getPlayerId(): ?int
    {
        return $this->playerId;
    }

    public function setPlayerId(int $playerId): static
    {
        $this->playerId = $playerId;
        return $this;
    }

    public function getWins(): ?int
    {
        return $this->wins;
    }

    public function setWins(?int $wins): static
    {
        $this->wins = $wins;
        return $this;
    }

    public function getWinPercentage(): ?int
    {
        return $this->winPercentage;
    }

    public function setWinPercentage(int $winPercentage): static
    {
        $this->winPercentage = $winPercentage;
        return $this;
    }

    public function getRoundAverage(): ?int
    {
        return $this->roundAverage;
    }

    public function setRoundAverage(?int $roundAverage): static
    {
        $this->roundAverage = $roundAverage;
        return $this;
    }

    public function getHighestCheckout(): ?int
    {
        return $this->highestCheckout;
    }

    public function setHighestCheckout(?int $highestCheckout): static
    {
        $this->highestCheckout = $highestCheckout;
        return $this;
    }

    public function getGamesPlayed(): ?int
    {
        return $this->gamesPlayed;
    }

    public function setGamesPlayed(?int $gamesPlayed): static
    {
        $this->gamesPlayed = $gamesPlayed;
        return $this;
    }
}
