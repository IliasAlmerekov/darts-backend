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
     * @param Game                $game
     * @param GameSettingsRequest $dto
     *
     * @return void
     */
    public function updateSettings(Game $game, GameSettingsRequest $dto): void;
}
