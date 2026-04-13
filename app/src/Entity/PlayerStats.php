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
 * @psalm-suppress PossiblyUnusedMethod
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
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @return int|null
     */
    public function getPlayerId(): ?int
    {
        return $this->playerId;
    }

    /**
     * @psalm-suppress PossiblyUnusedMethod
     *
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
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @return int|null
     */
    public function getWins(): ?int
    {
        return $this->wins;
    }

    /**
     * @psalm-suppress PossiblyUnusedMethod
     *
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
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @return int|null
     */
    public function getWinPercentage(): ?int
    {
        return $this->winPercentage;
    }

    /**
     * @psalm-suppress PossiblyUnusedMethod
     *
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
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @return int|null
     */
    public function getRoundAverage(): ?int
    {
        return $this->roundAverage;
    }

    /**
     * @psalm-suppress PossiblyUnusedMethod
     *
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
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @return int|null
     */
    public function getHighestCheckout(): ?int
    {
        return $this->highestCheckout;
    }

    /**
     * @psalm-suppress PossiblyUnusedMethod
     *
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
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @return int|null
     */
    public function getGamesPlayed(): ?int
    {
        return $this->gamesPlayed;
    }

    /**
     * @psalm-suppress PossiblyUnusedMethod
     *
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
