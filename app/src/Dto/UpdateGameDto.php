<?php

namespace App\Dto;

use App\Enum\GameStatus;
use Symfony\Component\Validator\Constraints as Assert;

class UpdateGameDto
{
    #[Assert\Choice(callback: [GameStatus::class, 'cases'])]
    public ?GameStatus $status = null;

    #[Assert\Positive]
    public ?int $round = null;

    #[Assert\Positive]
    #[Assert\Range(min: 101, max: 701)]
    public ?int $startscore = null;

    public ?bool $doubleout = null;

    public ?bool $tripleout = null;
}
