<?php
namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class GameFinishDto
{
    public function __construct(
        #[Assert\Type(\DateTimeInterface::class)]
        public readonly ?\DateTimeInterface $finishedAt = null,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            finishedAt: isset($data['finishedAt'])
                ? new \DateTimeImmutable($data['finishedAt'])
                : null
        );
    }
}
