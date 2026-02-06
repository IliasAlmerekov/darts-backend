<?php

declare(strict_types=1);

namespace App\Tests\Dto;

use App\Dto\RoomCreateRequest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class RoomCreateRequestValidationTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }

    public function testValidPayloadPassesValidation(): void
    {
        $dto = new RoomCreateRequest(
            previousGameId: 12,
            playerIds: [1, 2, 3],
            excludePlayerIds: [2],
        );

        $violations = $this->validator->validate($dto);

        self::assertCount(0, $violations);
    }

    public function testRejectsInvalidPreviousGameId(): void
    {
        $dto = new RoomCreateRequest(previousGameId: 0);

        $violations = $this->validator->validate($dto);

        self::assertGreaterThan(0, $violations->count());
        self::assertTrue($this->hasPropertyPath($violations, 'previousGameId'));
    }

    public function testRejectsInvalidPlayerLists(): void
    {
        $dto = new RoomCreateRequest(
            previousGameId: 10,
            playerIds: [1, 1, -2, 'x'],
            excludePlayerIds: [2, 2],
        );

        $violations = $this->validator->validate($dto);

        self::assertGreaterThan(0, $violations->count());
        self::assertTrue($this->hasPropertyPath($violations, 'playerIds'));
        self::assertTrue($this->hasPropertyPath($violations, 'excludePlayerIds'));
    }

    /**
     * @param iterable<\Symfony\Component\Validator\ConstraintViolationInterface> $violations
     * @param string                                                               $path
     *
     * @return bool
     */
    private function hasPropertyPath(iterable $violations, string $path): bool
    {
        foreach ($violations as $violation) {
            if ($path === $violation->getPropertyPath() || str_starts_with($violation->getPropertyPath(), $path.'[')) {
                return true;
            }
        }

        return false;
    }
}
