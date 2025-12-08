<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @psalm-immutable
 * DTO for updating game settings.
 */
final class GameSettingsRequest
{
    #[Assert\Choice(choices: [101, 201, 301, 401, 501])]
    #[SerializedName('startScore')]
    public ?int $startScore = null;

    #[Assert\Type('bool')]
    #[SerializedName('doubleOut')]
    public ?bool $doubleOut = null;

    #[Assert\Type('bool')]
    #[SerializedName('tripleOut')]
    public ?bool $tripleOut = null;
}
