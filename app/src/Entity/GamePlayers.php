<?php

namespace App\Entity;

use App\Repository\GamePlayersRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GamePlayersRepository::class)]
class GamePlayers
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $gamePlayerId = null;

    #[ORM\Column]
    private ?int $gameId = null;

    #[ORM\Column]
    private ?int $playerId = null;

    #[ORM\Column(nullable: true)]
    private ?int $position = null;

    #[ORM\Column(nullable: true)]
    private ?int $score = null;

    public function getGameId(): ?int
    {
        return $this->gameId;
    }

    public function setGameId(int $gameId): static
    {
        $this->gameId = $gameId;

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

    public function getPosition(): ?int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getScore(): ?int
    {
        return $this->score;
    }

    public function setScore(int $score): static
    {
        $this->score = $score;

        return $this;
    }

    public function getGamePlayerId(): ?int
    {
        return $this->gamePlayerId;
    }

    public function setGamePlayerId(int $gamePlayerId): static
    {
        $this->gamePlayerId = $gamePlayerId;

        return $this;
    }
}
