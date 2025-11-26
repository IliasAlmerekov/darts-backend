<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class ThrowRequest
{
    #[Assert\NotNull]
    #[Assert\Positive]
    public ?int $playerId = null;

    #[Assert\NotNull]
    #[Assert\Range(min: 0, max: 60)]
    public ?int $value = null;

    #[Assert\Type('bool')]
    public ?bool $isDouble = null;

    #[Assert\Type('bool')]
    public ?bool $isTriple = null;

    #[Assert\Type('bool')]
    public ?bool $isBust = null;
}
