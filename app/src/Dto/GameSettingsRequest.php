<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\SerializedName;
use App\Entity\Game;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @psalm-immutable
 * DTO for updating game settings.
 */
final class GameSettingsRequest
{
    /**
     * @param int|null    $startScore
     * @param string|null $outMode
     * @param bool|null   $doubleOut
     * @param bool|null   $tripleOut
     */
    public function __construct(
        #[Assert\Choice(choices: Game::ALLOWED_START_SCORES)]
        #[SerializedName('startScore')]
        public ?int $startScore = null,
        #[Assert\Choice(choices: ['singleout', 'doubleout', 'tripleout'])]
        #[SerializedName('outMode')]
        public ?string $outMode = null,
        #[Assert\Type('bool')]
        #[SerializedName('doubleOut')]
        public ?bool $doubleOut = null,
        #[Assert\Type('bool')]
        #[SerializedName('tripleOut')]
        public ?bool $tripleOut = null,
    ) {
    }
}
