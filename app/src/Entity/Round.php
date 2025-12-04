<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RoundRepository;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass: RoundRepository::class)
 * This class represents a round of a game.
 */
#[ORM\Entity(repositoryClass: RoundRepository::class)]
class Round
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $roundId = null;
    #[ORM\ManyToOne(inversedBy: 'rounds')]
    #[ORM\JoinColumn(referencedColumnName: 'game_id', nullable: false)]
    private ?Game $game = null;
    #[ORM\Column]
    private ?int $roundNumber = null;
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?DateTimeInterface $startedAt = null;
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?DateTimeInterface $finishedAt = null;
    #[ORM\OneToMany(
        targetEntity: RoundThrows::class,
        mappedBy: 'round',
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    private Collection $roundThrows;

    /**
     * @return void
     */
    public function __construct()
    {
        $this->roundThrows = new ArrayCollection();
    }

    /**
     * @return int|null
     */
    public function getRoundId(): ?int
    {
        return $this->roundId;
    }

    /**
     * @param int $roundId
     *
     * @return static
     */
    public function setRoundId(int $roundId): static
    {
        $this->roundId = $roundId;
        return $this;
    }

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
     * @return int|null
     */
    public function getRoundNumber(): ?int
    {
        return $this->roundNumber;
    }

    /**
     * @param int $roundNumber
     *
     * @return static
     */
    public function setRoundNumber(int $roundNumber): static
    {
        $this->roundNumber = $roundNumber;
        return $this;
    }

    /**
     * @psalm-suppress PossiblyUnusedMethod Doctrine getter может не вызываться напрямую
     *
     * @return DateTimeInterface|null
     */
    public function getStartedAt(): ?DateTimeInterface
    {
        return $this->startedAt;
    }

    /**
     * @param DateTimeInterface|null $startedAt
     *
     * @return static
     */
    public function setStartedAt(?DateTimeInterface $startedAt): static
    {
        $this->startedAt = $startedAt;
        return $this;
    }

    /**
     * @return DateTimeInterface|null
     */
    public function getFinishedAt(): ?DateTimeInterface
    {
        return $this->finishedAt;
    }

    /**
     * @param DateTimeInterface|null $finishedAt
     *
     * @return static
     */
    public function setFinishedAt(?DateTimeInterface $finishedAt): static
    {
        $this->finishedAt = $finishedAt;
        return $this;
    }

    /**
     * @return Collection<int, RoundThrows>
     */
    public function getRoundThrows(): Collection
    {
        return $this->roundThrows;
    }

    /**
     * @param RoundThrows $roundThrow
     *
     * @return static
     */
    public function addRoundThrow(RoundThrows $roundThrow): static
    {
        if (!$this->roundThrows->contains($roundThrow)) {
            $this->roundThrows->add($roundThrow);
            $roundThrow->setRound($this);
        }

        return $this;
    }

    /**
     * @param RoundThrows $roundThrow
     *
     * @return static
     */
    public function removeRoundThrow(RoundThrows $roundThrow): static
    {
        if ($this->roundThrows->removeElement($roundThrow)) {
// orphanRemoval will delete on a flush
        }

        return $this;
    }
}
