<?php

namespace App\Entity;

use App\Repository\RoundRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RoundRepository::class)]
class Round
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $roundId = null;

    #[ORM\ManyToOne(inversedBy: 'rounds')]
    #[ORM\JoinColumn(nullable: false, referencedColumnName: 'game_id')]
    private ?Game $game = null;

    #[ORM\Column]
    private ?int $roundNumber = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $startedAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $finishedAt = null;

    #[ORM\OneToMany(mappedBy: 'round', targetEntity: RoundThrows::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $roundThrows;

    public function __construct()
    {
        $this->roundThrows = new ArrayCollection();
    }

    public function getRoundId(): ?int
    {
        return $this->roundId;
    }

    public function setRoundId(int $roundId): static
    {
        $this->roundId = $roundId;

        return $this;
    }

    public function getGame(): ?Game
    {
        return $this->game;
    }

    public function setGame(?Game $game): static
    {
        $this->game = $game;

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

    public function getStartedAt(): ?\DateTimeInterface
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeInterface $startedAt): static
    {
        $this->startedAt = $startedAt;

        return $this;
    }

    public function getFinishedAt(): ?\DateTimeInterface
    {
        return $this->finishedAt;
    }

    public function setFinishedAt(?\DateTimeInterface $finishedAt): static
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

    public function addRoundThrow(RoundThrows $roundThrow): static
    {
        if (!$this->roundThrows->contains($roundThrow)) {
            $this->roundThrows->add($roundThrow);
            $roundThrow->setRound($this);
        }

        return $this;
    }

    public function removeRoundThrow(RoundThrows $roundThrow): static
    {
        if ($this->roundThrows->removeElement($roundThrow)) {
            // orphanRemoval will delete on flush
        }

        return $this;
    }
}
