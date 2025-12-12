<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Dto;

/**
 * @psalm-immutable
 * This class is used to serialize game stats
 */
final class GameResponseDto
{
    /**
     * @param int                                       $id
     * @param string                                    $status
     * @param int                                       $currentRound
     * @param int|null                                  $activePlayerId
     * @param int                                       $currentThrowCount
     * @param list<PlayerResponseDto>                   $players
     * @param int|null                                  $winnerId
     * @param array<string, int|bool|string|null|array> $settings
     */
    public function __construct(public int $id, public string $status, public int $currentRound, public ?int $activePlayerId, public int $currentThrowCount, public array $players, public ?int $winnerId, public array $settings)
    {
    }
}
