<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @psalm-immutable
 * This class is used to serialize game start request
 */
final class StartGameRequest
{
    #[Assert\Choice(choices: [101, 201, 301, 401, 501])]
    #[SerializedName('startscore')]
    public ?int $startScore = null;
    #[Assert\Type('bool')]
    #[SerializedName('doubleout')]
    public ?bool $doubleOut = null;
    #[Assert\Type('bool')]
    #[SerializedName('tripleout')]
    public ?bool $tripleOut = null;
    /**
     * @var list<int>|null
     */
    #[Assert\When(expression: 'this.playerPositions !== null', constraints: [
        new Assert\Count(min: 2, max: 10),
        new Assert\All([
            new Assert\Type('integer'),
            new Assert\Positive(),
        ]),
    ])]
    public ?array $playerPositions = null;
}
