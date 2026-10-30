<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\InvitationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV4;

/**
 * @psalm-suppress ClassMustBeFinal Doctrine lazy-loading proxies extend entities.
 *
 * @ORM\Entity(repositoryClass: InvitationRepository::class)
 * This class represents an invitation to join a game.
 */
#[ORM\Entity(repositoryClass: InvitationRepository::class)]
class Invitation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;
    #[ORM\Column(length: 255, unique: true)]
    private ?string $uuid = null;
    #[ORM\OneToOne(targetEntity: Game::class)]
    #[ORM\JoinColumn(name: 'game_id', referencedColumnName: 'game_id', nullable: false, onDelete: 'CASCADE')]
    private ?Game $game = null;

    /**
     *
     * @return string|null
     */
    public function getUuid(): ?string
    {
        return $this->uuid;
    }

    /**
     * @param UuidV4|string $uuid
     *
     * @return static
     */
    public function setUuid(UuidV4|string $uuid): static
    {
        $this->uuid = (string) $uuid;

        return $this;
    }

    /**
     *
     * @return int|null
     */
    public function getGameId(): ?int
    {
        return $this->game?->getGameId();
    }

    /**
     * @param Game $game
     *
     * @return static
     */
    public function setGame(Game $game): static
    {
        $this->game = $game;

        return $this;
    }

    /**
     * @return int|null
     */
    public function getId(): ?int
    {
        return $this->id;
    }
}
