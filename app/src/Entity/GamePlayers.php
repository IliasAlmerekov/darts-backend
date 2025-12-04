<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\GamePlayersRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass: GamePlayersRepository::class)
 * This class represents the association between a Game and its Players.
 */
#[ORM\Entity(repositoryClass: GamePlayersRepository::class)]
class GamePlayers
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $gamePlayerId = null;
    #[ORM\ManyToOne(inversedBy: 'gamePlayers')]
    #[ORM\JoinColumn(referencedColumnName: 'game_id', nullable: false)]
    private ?Game $game = null;
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $player = null;
    #[ORM\Column(nullable: true)]
    private ?int $position = null;
    #[ORM\Column(nullable: true)]
    private ?int $score = null;
    #[ORM\Column(nullable: true)]
    private ?bool $isWinner = null;
    public function getGame(): ?Game
    {
        return $this->game;
    }

    public function setGame(?Game $game): static
    {
        $this->game = $game;
        return $this;
    }

    public function getPlayer(): ?User
    {
        return $this->player;
    }

    public function setPlayer(?User $player): static
    {
        $this->player = $player;
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

    public function isWinner(): ?bool
    {
        return $this->isWinner;
    }

    public function setIsWinner(?bool $isWinner): static
    {
        $this->isWinner = $isWinner;
        return $this;
    }
}
