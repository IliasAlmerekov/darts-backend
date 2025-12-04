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

    /**
     * @return Game|null
     */
    public function getGame(): ?Game
    {
        return $this->game;
    }

    /**
     * @param Game|null $game
     *
     * @return static
     */
    public function setGame(?Game $game): static
    {
        $this->game = $game;
        return $this;
    }

    /**
     * @return User|null
     */
    public function getPlayer(): ?User
    {
        return $this->player;
    }

    /**
     * @param User|null $player
     *
     * @return static
     */
    public function setPlayer(?User $player): static
    {
        $this->player = $player;
        return $this;
    }

    /**
     * @return int|null
     */
    public function getPosition(): ?int
    {
        return $this->position;
    }

    /**
     * @param int $position
     *
     * @return static
     */
    public function setPosition(int $position): static
    {
        $this->position = $position;
        return $this;
    }

    /**
     * @return int|null
     */
    public function getScore(): ?int
    {
        return $this->score;
    }

    /**
     * @param int $score
     *
     * @return static
     */
    public function setScore(int $score): static
    {
        $this->score = $score;
        return $this;
    }

    /**
     * @return int|null
     */
    public function getGamePlayerId(): ?int
    {
        return $this->gamePlayerId;
    }

    /**
     * @param int $gamePlayerId
     *
     * @return static
     */
    public function setGamePlayerId(int $gamePlayerId): static
    {
        $this->gamePlayerId = $gamePlayerId;
        return $this;
    }

    /**
     * @return bool|null
     */
    public function isWinner(): ?bool
    {
        return $this->isWinner;
    }

    /**
     * @param bool|null $isWinner
     *
     * @return static
     */
    public function setIsWinner(?bool $isWinner): static
    {
        $this->isWinner = $isWinner;
        return $this;
    }
}
