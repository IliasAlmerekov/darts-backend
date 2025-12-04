<?php

declare(strict_types=1);

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
class RoundThrows
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
     * @return Round|null
     */
    public function getRound(): ?Round
    {
        return $this->round;
    }

    /**
     * @param Round|null $round
     *
     * @return static
     */
    public function setRound(?Round $round): static
    {
        $this->round = $round;

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
    public function getThrowNumber(): ?int
    {
        return $this->throwNumber;
    }

    /**
     * @param int $throwNumber
     *
     * @return static
     */
    public function setThrowNumber(int $throwNumber): static
    {
        $this->throwNumber = $throwNumber;

        return $this;
    }

    /**
     * @return int|null
     */
    public function getValue(): ?int
    {
        return $this->value;
    }

    /**
     * @param int $value
     *
     * @return static
     */
    public function setValue(int $value): static
    {
        $this->value = $value;

        return $this;
    }

    /**
     * @return bool
     */
    public function isBust(): bool
    {
        return $this->isBust;
    }

    /**
     * @param bool $isBust
     *
     * @return static
     */
    public function setIsBust(bool $isBust): static
    {
        $this->isBust = $isBust;

        return $this;
    }

    /**
     * @return bool
     */
    public function isDouble(): bool
    {
        return $this->isDouble;
    }

    /**
     * @param bool $isDouble
     *
     * @return static
     */
    public function setIsDouble(bool $isDouble): static
    {
        $this->isDouble = $isDouble;

        return $this;
    }

    /**
     * @return bool
     */
    public function isTriple(): bool
    {
        return $this->isTriple;
    }

    /**
     * @param bool $isTriple
     *
     * @return static
     */
    public function setIsTriple(bool $isTriple): static
    {
        $this->isTriple = $isTriple;

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
     * @return DateTimeInterface|null
     */
    public function getTimestamp(): ?DateTimeInterface
    {
        return $this->timestamp;
    }

    /**
     * @param DateTimeInterface $timestamp
     *
     * @return static
     */
    public function setTimestamp(DateTimeInterface $timestamp): static
    {
        $this->timestamp = $timestamp;

        return $this;
    }

    /**
     * @return int|null
     */
    public function getThrowId(): ?int
    {
        return $this->throwId;
    }

    /**
     * @param int $throwId
     *
     * @return static
     */
    public function setThrowId(int $throwId): static
    {
        $this->throwId = $throwId;

        return $this;
    }
}
