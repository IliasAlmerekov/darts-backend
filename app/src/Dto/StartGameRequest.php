<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class StartGameRequest
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
    #[Assert\When(
        expression: 'this.playerPositions !== null',
        constraints: [
            new Assert\Count(min: 2, max: 10),
            new Assert\All([
                new Assert\Type('integer'),
                new Assert\Positive,
            ]),
        ]
    )]
    public ?array $playerPositions = null;
}
