<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\GameStatus;
use App\Repository\GameRepository;
use DateTime;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass: GameRepository::class)
 * This class represents a game
 */
#[ORM\Entity(repositoryClass: GameRepository::class)]
class Game
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $gameId = null;

    #[ORM\Column(nullable: true)]
    private ?int $type = null;

    #[ORM\ManyToOne]
    private ?User $winner = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?DateTime $date = null;

    #[ORM\Column(nullable: true, options: ['default' => 301])]
    private int $startScore = 301;

    #[ORM\Column(options: ['default' => false])]
    private bool $doubleOut = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $tripleOut = false;

    #[ORM\Column(enumType: GameStatus::class, options: ['default' => GameStatus::Lobby->value])]
    private GameStatus $status = GameStatus::Lobby;

    #[ORM\OneToMany(
        targetEntity: GamePlayers::class,
        mappedBy: 'game',
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    private Collection $gamePlayers;

    #[ORM\OneToMany(targetEntity: Round::class, mappedBy: 'game', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $rounds;

    #[ORM\OneToOne(cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(nullable: true)]
    private ?Invitation $invitation = null;

    #[ORM\Column(nullable: true)]
    private ?int $round = null;

    #[ORM\Column(type: Types::TIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $finishedAt = null;

    /**
     * @return void
     */
    public function __construct()
    {
        $this->gamePlayers = new ArrayCollection();
        $this->rounds = new ArrayCollection();
    }

    /**
     * @return int|null
     */
    public function getType(): ?int
    {
        return $this->type;
    }

    /**
     * @param int $type
     *
     * @return static
     */
    public function setType(int $type): static
    {
        $this->type = $type;

        return $this;
    }

    /**
     * @return User|null
     */
    public function getWinner(): ?User
    {
        return $this->winner;
    }

    /**
     * @param User|null $winner
     *
     * @return static
     */
    public function setWinner(?User $winner): static
    {
        $this->winner = $winner;

        return $this;
    }

    /**
     * @return DateTime|null
     */
    public function getDate(): ?DateTime
    {
        return $this->date;
    }

    /**
     * @param DateTime $date
     *
     * @return static
     */
    public function setDate(DateTime $date): static
    {
        $this->date = $date;

        return $this;
    }

    /**
     * @return int
     */
    public function getStartScore(): int
    {
        return $this->startScore;
    }

    /**
     * @param int $startScore
     *
     * @return static
     */
    public function setStartScore(int $startScore): static
    {
        $this->startScore = $startScore;

        return $this;
    }

    /**
     * @return bool
     */
    public function isDoubleOut(): bool
    {
        return $this->doubleOut;
    }

    /**
     * @param bool $doubleOut
     *
     * @return static
     */
    public function setDoubleOut(bool $doubleOut): static
    {
        $this->doubleOut = $doubleOut;

        return $this;
    }

    /**
     * @return bool
     */
    public function isTripleOut(): bool
    {
        return $this->tripleOut;
    }

    /**
     * @param bool $tripleOut
     *
     * @return static
     */
    public function setTripleOut(bool $tripleOut): static
    {
        $this->tripleOut = $tripleOut;

        return $this;
    }

    /**
     * @return GameStatus
     */
    public function getStatus(): GameStatus
    {
        return $this->status;
    }

    /**
     * @param GameStatus $status
     *
     * @return static
     */
    public function setStatus(GameStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    /**
     * @return int|null
     */
    public function getRound(): ?int
    {
        return $this->round;
    }

    /**
     * @param int|null $round
     *
     * @return static
     */
    public function setRound(?int $round): static
    {
        $this->round = $round;

        return $this;
    }

    /**
     * @return Collection<int, GamePlayers>
     */
    public function getGamePlayers(): Collection
    {
        return $this->gamePlayers;
    }

    /**
     * @param GamePlayers $gamePlayer
     *
     * @return static
     */
    public function addGamePlayer(GamePlayers $gamePlayer): static
    {
        if (!$this->gamePlayers->contains($gamePlayer)) {
            $this->gamePlayers->add($gamePlayer);
            $gamePlayer->setGame($this);
        }

        return $this;
    }

    /**
     * @param GamePlayers $gamePlayer
     *
     * @return static
     */
    public function removeGamePlayer(GamePlayers $gamePlayer): static
    {
        if ($this->gamePlayers->removeElement($gamePlayer)) {
            // set the owning side to null (unless already changed)
            if ($gamePlayer->getGame() === $this) {
                $gamePlayer->setGame(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Round>
     */
    public function getRounds(): Collection
    {
        return $this->rounds;
    }

    /**
     * @param Round $round
     *
     * @return static
     */
    public function addRound(Round $round): static
    {
        if (!$this->rounds->contains($round)) {
            $this->rounds->add($round);
            $round->setGame($this);
        }

        return $this;
    }

    /**
     * @param Round $round
     *
     * @return static
     */
    public function removeRound(Round $round): static
    {
        if ($this->rounds->removeElement($round)) {
            // set the owning side to null (unless already changed)
            if ($round->getGame() === $this) {
                $round->setGame(null);
            }
        }

        return $this;
    }

    /**
     * @return Invitation|null
     */
    public function getInvitation(): ?Invitation
    {
        return $this->invitation;
    }

    /**
     * @param Invitation|null $invitation
     *
     * @return static
     */
    public function setInvitation(?Invitation $invitation): static
    {
        $this->invitation = $invitation;

        return $this;
    }

    /**
     * @return int|null
     */
    public function getGameId(): ?int
    {
        return $this->gameId;
    }

    /**
     * @param int $gameId
     *
     * @return static
     */
    public function setGameId(int $gameId): static
    {
        $this->gameId = $gameId;

        return $this;
    }

    /**
     * @return DateTimeImmutable|null
     */
    public function getFinishedAt(): ?DateTimeImmutable
    {
        return $this->finishedAt;
    }

    /**
     * @param DateTimeImmutable|null $finishedAt
     *
     * @return static
     */
    public function setFinishedAt(?DateTimeImmutable $finishedAt): static
    {
        $this->finishedAt = $finishedAt;

        return $this;
    }
}
