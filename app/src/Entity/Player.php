<?php

namespace App\Entity;

use App\Repository\PlayerRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PlayerRepository::class)]
class Player
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $playerId = null;

    #[ORM\Column(length: 255)]
    private ?string $playerName = null;

    public function getPlayerName(): ?string
    {
        return $this->playerName;
    }

    public function setPlayerName(string $Name): static
    {
        $this->playerName = $Name;

        return $this;
    }

    public function getPlayerId(): ?int
    {
        return $this->playerId;
    }

    public function setPlayerId(int $playerId): static
    {
        $this->playerId = $playerId;

        return $this;
    }
}
