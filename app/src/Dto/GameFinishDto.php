<?php

declare(strict_types=1);

namespace App\Dto;

use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @psalm-immutable
 * This class is used to serialize game stats
 */
final readonly class GameFinishDto
{
    /**
     * @param DateTimeInterface|null $finishedAt
     */
    public function __construct(
        #[Assert\Type(DateTimeInterface::class)]
        public ?DateTimeInterface $finishedAt = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws Exception
     *
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            finishedAt: isset($data['finishedAt'])
                ? new DateTimeImmutable($data['finishedAt'])
                : null,
        );
    }
}
