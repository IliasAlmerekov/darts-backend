<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PlayerStatsRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass: PlayerStatsRepository::class)
 * This class represents the statistics of a player.
 *
 * @psalm-suppress UnusedClass
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

    /**
     * @return int|null
     */
    public function getPlayerId(): ?int
    {
        return $this->playerId;
    }

    /**
     * @param int $playerId
     *
     * @return static
     */
    public function setPlayerId(int $playerId): static
    {
        $this->playerId = $playerId;

        return $this;
    }

    /**
     * @return int|null
     */
    public function getWins(): ?int
    {
        return $this->wins;
    }

    /**
     * @param int|null $wins
     *
     * @return static
     */
    public function setWins(?int $wins): static
    {
        $this->wins = $wins;

        return $this;
    }

    /**
     * @return int|null
     */
    public function getWinPercentage(): ?int
    {
        return $this->winPercentage;
    }

    /**
     * @param int $winPercentage
     *
     * @return static
     */
    public function setWinPercentage(int $winPercentage): static
    {
        $this->winPercentage = $winPercentage;

        return $this;
    }

    /**
     * @return int|null
     */
    public function getRoundAverage(): ?int
    {
        return $this->roundAverage;
    }

    /**
     * @param int|null $roundAverage
     *
     * @return static
     */
    public function setRoundAverage(?int $roundAverage): static
    {
        $this->roundAverage = $roundAverage;

        return $this;
    }

    /**
     * @return int|null
     */
    public function getHighestCheckout(): ?int
    {
        return $this->highestCheckout;
    }

    /**
     * @param int|null $highestCheckout
     *
     * @return static
     */
    public function setHighestCheckout(?int $highestCheckout): static
    {
        $this->highestCheckout = $highestCheckout;

        return $this;
    }

    /**
     * @return int|null
     */
    public function getGamesPlayed(): ?int
    {
        return $this->gamesPlayed;
    }

    /**
     * @param int|null $gamesPlayed
     *
     * @return static
     */
    public function setGamesPlayed(?int $gamesPlayed): static
    {
        $this->gamesPlayed = $gamesPlayed;

        return $this;
    }
}
