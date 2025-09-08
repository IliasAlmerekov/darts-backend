<?php

namespace App\Entity;

use App\Repository\RoundRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RoundRepository::class)]
class Round
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $roundId = null;

    #[ORM\Column]
    private ?int $gameId = null;

    #[ORM\Column]
    private ?int $roundNumber = null;

    public function getRoundId(): ?int
    {
        return $this->roundId;
    }

    public function setRoundId(int $roundId): static
    {
        $this->roundId = $roundId;

        return $this;
    }

    public function getGameId(): ?int
    {
        return $this->gameId;
    }

    public function setGameId(int $gameId): static
    {
        $this->gameId = $gameId;

        return $this;
    }

    public function getRoundNumber(): ?int
    {
        return $this->roundNumber;
    }

    public function setRoundNumber(int $roundNumber): static
    {
        $this->roundNumber = $roundNumber;

        return $this;
    }
}
