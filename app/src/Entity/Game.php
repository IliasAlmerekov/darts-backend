<?php declare(strict_types=1);

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
final class Game
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

    #[ORM\OneToMany(targetEntity: GamePlayers::class, mappedBy: 'game', cascade: ['persist', 'remove'], orphanRemoval: true)]
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

    public function __construct()
    {
        $this->gamePlayers = new ArrayCollection();
        $this->rounds = new ArrayCollection();
    }

    public function getType(): ?int
    {
        return $this->type;
    }

    public function setType(int $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getWinner(): ?User
    {
        return $this->winner;
    }

    public function setWinner(?User $winner): static
    {
        $this->winner = $winner;

        return $this;
    }

    public function getDate(): ?DateTime
    {
        return $this->date;
    }

    public function setDate(DateTime $date): static
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

    public function addGamePlayer(GamePlayers $gamePlayer): static
    {
        if (!$this->gamePlayers->contains($gamePlayer)) {
            $this->gamePlayers->add($gamePlayer);
            $gamePlayer->setGame($this);
        }

        return $this;
    }

    public function removeGamePlayer(GamePlayers $gamePlayer): static
    {

        return $this;
    }

    /**
     * @return Collection<int, Round>
     */
    public function getRounds(): Collection
    {
        return $this->rounds;
    }

    public function addRound(Round $round): static
    {
        if (!$this->rounds->contains($round)) {
            $this->rounds->add($round);
            $round->setGame($this);
        }

        return $this;
    }

    public function removeRound(Round $round): static
    {

        return $this;
    }

    public function getInvitation(): ?Invitation
    {
        return $this->invitation;
    }

    public function setInvitation(?Invitation $invitation): static
    {
        $this->invitation = $invitation;

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

    public function getFinishedAt(): ?DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function setFinishedAt(?DateTimeImmutable $finishedAt): static
    {
        $this->finishedAt = $finishedAt;

        return $this;
    }
}
