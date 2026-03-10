<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Dto\StartGameRequest;
use App\Entity\Game;
use App\Exception\ApiExceptionInterface;

/**
 * Interface for combined rematch creation and game start operations.
 */
interface RematchStartServiceInterface
{
    /**
     * Creates a rematch from the given game and starts it with the provided settings.
     *
     * @param int              $oldGameId
     * @param StartGameRequest $dto
     *
     * @throws ApiExceptionInterface
     *
     * @return Game
     */
    public function createAndStart(int $oldGameId, StartGameRequest $dto): Game;
}
