<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\OutRule;

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
#[ORM\Table(name: 'game')]
#[ORM\Index(name: 'idx_game_status_game_id', columns: ['status', 'game_id'])]
#[ORM\Index(name: 'idx_game_status_finished_at_game_id', columns: ['status', 'finished_at', 'game_id'])]
#[ORM\UniqueConstraint(name: 'uniq_game_open_lobby', columns: ['open_lobby'])]
class Game
{
    /** The most participants a game can have. */
    public const int MAX_PARTICIPANTS = 10;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $gameId = null;

    #[ORM\ManyToOne]
    private ?User $winner = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?DateTime $date = null;

    #[ORM\Column(options: ['default' => 301])]
    private int $startScore = 301;

    #[ORM\Column(enumType: OutRule::class, options: ['default' => OutRule::Single->value])]
    private OutRule $outRule = OutRule::Single;

    #[ORM\Column(enumType: GameStatus::class, options: ['default' => GameStatus::Lobby->value])]
    private GameStatus $status = GameStatus::Lobby;

    /**
     * TRUE while the game is in the lobby, NULL otherwise. MySQL computes the value; the unique
     * index on it allows at most one game in the lobby, because MySQL has no partial indexes.
     *
     * @psalm-suppress UnusedProperty Reason: exists only so the schema tools know the column.
     */
    #[ORM\Column(
        name: 'open_lobby',
        type: Types::BOOLEAN,
        nullable: true,
        insertable: false,
        updatable: false,
        columnDefinition: "TINYINT GENERATED ALWAYS AS (IF(status = 'lobby', 1, NULL)) VIRTUAL",
        generated: 'ALWAYS',
    )]
    private ?bool $openLobby = null;

    #[ORM\OneToMany(
        targetEntity: GamePlayers::class,
        mappedBy: 'game',
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    private Collection $gamePlayers;

    #[ORM\OneToMany(targetEntity: Round::class, mappedBy: 'game', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $rounds;

    #[ORM\Column(nullable: true)]
    private ?int $round = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $finishedAt = null;

    /**
     * @psalm-suppress PossiblyUnusedMethod Reason: lobbies are inserted through DBAL (ADR 0003); only tests construct games.
     *
     * @return void
     */
    public function __construct()
    {
        $this->gamePlayers = new ArrayCollection();
        $this->rounds = new ArrayCollection();
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
     * @psalm-suppress PossiblyUnusedMethod
     *
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
     *
     * @psalm-suppress PossiblyUnusedMethod Reason: lobbies are inserted through DBAL (ADR 0003); only tests set the date.
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

    /** @return OutRule */
    public function outRule(): OutRule
    {
        return $this->outRule;
    }

    /**
     * @param OutRule $outRule
     *
     * @return static
     */
    public function setOutRule(OutRule $outRule): static
    {
        $this->outRule = $outRule;

        return $this;
    }

    /** @return bool */
    public function isDoubleOut(): bool
    {
        return OutRule::Double === $this->outRule;
    }

    /** @return bool */
    public function isTripleOut(): bool
    {
        return OutRule::Triple === $this->outRule;
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
     * Undo and reopen use this rule: a seat with score left has no placement and cannot hold the win;
     * the seats without score left keep both.
     *
     * @return void
     */
    public function takeBackPlacementsFromSeatsWithScoreLeft(): void
    {
        foreach ($this->gamePlayers as $gamePlayer) {
            if (($gamePlayer->getScore() ?? $this->startScore) <= 0) {
                continue;
            }

            $gamePlayer->setPlacement(null);
            if (true === $gamePlayer->isWinner()) {
                $gamePlayer->setIsWinner(false);
                $this->winner = null;
            }
        }
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
