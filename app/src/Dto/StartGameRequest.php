<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Game;
use Symfony\Component\Validator\Constraints as Assert;
use Webmozart\Assert\Assert as WebmozartAssert;

/**
 * @psalm-immutable
 * This class is used to serialize game start request
 */
final class StartGameRequest
{
    #[Assert\Choice(choices: [101, 201, 301, 401, 501])]
    public ?int $startScore = null;
    #[Assert\Type('bool')]
    public ?bool $doubleOut = null;
    #[Assert\Type('bool')]
    public ?bool $tripleOut = null;
    /**
     * @var list<int>|null
     */
    #[Assert\Type('array')]
    #[Assert\Count(min: 2, max: Game::MAX_PARTICIPANTS)]
    #[Assert\All([
        new Assert\Type('integer'),
        new Assert\Positive(),
    ])]
    public ?array $playerPositions = null;

    /**
     * @param list<int> $playerPositions
     *
     * @return self
     */
    public function withPlayerPositions(array $playerPositions): self
    {
        $request = clone $this;
        $request->playerPositions = $playerPositions;

        return $request;
    }
}
