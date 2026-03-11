<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Dto\GameSettingsRequest;
use App\Entity\Game;

/**
 * Interface for updating game settings in a controlled way.
 */
interface GameSettingsServiceInterface
{
    /**
     * Apply validated settings without performing access checks.
     *
     * Intended for flows that already established authorization, such as room creation.
     *
     * @param Game                $game
     * @param GameSettingsRequest $dto
     *
     * @return void
     */
    public function applySettings(Game $game, GameSettingsRequest $dto): void;

    /**
     * @param Game                $game
     * @param GameSettingsRequest $dto
     *
     * @return void
     */
    public function updateSettings(Game $game, GameSettingsRequest $dto): void;
}
