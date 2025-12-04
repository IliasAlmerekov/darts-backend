<?php declare(strict_types=1);

namespace App\Entity;

use App\Repository\RoundThrowsRepository;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass: RoundThrowsRepository::class)
 * This class represents a round throw.
 */
#[ORM\Entity(repositoryClass: RoundThrowsRepository::class)]
final class RoundThrows
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $throwId = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(referencedColumnName: 'game_id', nullable: false)]
    private ?Game $game = null;

    #[ORM\ManyToOne(inversedBy: 'roundThrows')]
    #[ORM\JoinColumn(referencedColumnName: 'round_id', nullable: false)]
    private ?Round $round = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $player = null;

    #[ORM\Column]
    private ?int $throwNumber = null;

    #[ORM\Column]
    private ?int $value = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $isBust = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $isDouble = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $isTriple = false;

    #[ORM\Column]
    private ?int $score = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?DateTimeInterface $timestamp = null;

    public function getGame(): ?Game
    {
        return $this->game;
    }

    public function setGame(?Game $game): static
    {
        $this->game = $game;

        return $this;
    }

    public function getRound(): ?Round
    {
        return $this->round;
    }

    public function setRound(?Round $round): static
    {
        $this->round = $round;

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

    public function getThrowNumber(): ?int
    {
        return $this->throwNumber;
    }

    public function setThrowNumber(int $throwNumber): static
    {
        $this->throwNumber = $throwNumber;

        return $this;
    }

    public function getValue(): ?int
    {
        return $this->value;
    }

    public function setValue(int $value): static
    {
        $this->value = $value;

        return $this;
    }

    public function isBust(): bool
    {
        return $this->isBust;
    }

    public function setIsBust(bool $isBust): static
    {
        $this->isBust = $isBust;

        return $this;
    }

    public function isDouble(): bool
    {
        return $this->isDouble;
    }

    public function setIsDouble(bool $isDouble): static
    {
        $this->isDouble = $isDouble;

        return $this;
    }

    public function isTriple(): bool
    {
        return $this->isTriple;
    }

    public function setIsTriple(bool $isTriple): static
    {
        $this->isTriple = $isTriple;

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

    public function getTimestamp(): ?DateTimeInterface
    {
        return $this->timestamp;
    }

    public function setTimestamp(DateTimeInterface $timestamp): static
    {
        $this->timestamp = $timestamp;

        return $this;
    }

    public function getThrowId(): ?int
    {
        return $this->throwId;
    }

    public function setThrowId(int $throwId): static
    {
        $this->throwId = $throwId;

        return $this;
    }
}
