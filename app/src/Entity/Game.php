<?php

namespace App\Entity;

use App\Enum\GameStatus;
use App\Repository\GameRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GameRepository::class)]
class Game
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $gameId = null;

    #[ORM\Column(nullable: true)]
    private ?int $type = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $winner = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?\DateTime $date = null;

    #[ORM\Column(options: ['default' => 301], nullable: true)]
    private int $startScore = 301;

    #[ORM\Column(options: ['default' => false])]
    private bool $doubleOut = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $tripleOut = false;

    #[ORM\Column(enumType: GameStatus::class, options: ['default' => GameStatus::Lobby->value])]
    private GameStatus $status = GameStatus::Lobby;

    #[ORM\Column(nullable: true)]
    private ?int $round = null;

    public function getType(): ?int
    {
        return $this->type;
    }

    public function setType(int $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getWinner(): ?string
    {
        return $this->winner;
    }

    public function setWinner(?string $winner): static
    {
        $this->winner = $winner;

        return $this;
    }

    public function getDate(): ?\DateTime
    {
        return $this->date;
    }

    public function setDate(\DateTime $date): static
    {
        $this->date = $date;

        return $this;
    }

    public function getStartScore(): int
    {
        return $this->startScore;
    }

    public function setStartScore(int $startScore): static
    {
        $this->startScore = $startScore;

        return $this;
    }

    public function isDoubleOut(): bool
    {
        return $this->doubleOut;
    }

    public function setDoubleOut(bool $doubleOut): static
    {
        $this->doubleOut = $doubleOut;

        return $this;
    }

    public function isTripleOut(): bool
    {
        return $this->tripleOut;
    }

    public function setTripleOut(bool $tripleOut): static
    {
        $this->tripleOut = $tripleOut;

        return $this;
    }

    public function getStatus(): GameStatus
    {
        return $this->status;
    }

    public function setStatus(GameStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getRound(): ?int
    {
        return $this->round;
    }

    public function setRound(int $round): static
    {
        $this->round = $round;

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
}
