<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\ViolationCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\Validation;

final class ViolationCodeTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, Constraint|list<Constraint>, string}>
     */
    public static function constraints(): iterable
    {
        yield 'NotBlank' => ['', new Assert\NotBlank(), 'REQUIRED'];
        yield 'NotNull' => [null, new Assert\NotNull(), 'REQUIRED'];
        yield 'Length min' => ['ab', new Assert\Length(min: 3, max: 5), 'TOO_SHORT'];
        yield 'Length max' => ['abcdef', new Assert\Length(min: 3, max: 5), 'TOO_LONG'];
        yield 'Length exact, too short' => ['ab', new Assert\Length(exactly: 3), 'TOO_SHORT'];
        yield 'Length exact, too long' => ['abcd', new Assert\Length(exactly: 3), 'TOO_LONG'];
        yield 'Email' => ['nope', new Assert\Email(), 'INVALID_EMAIL'];
        yield 'Choice' => [4, new Assert\Choice(choices: [1, 2]), 'INVALID_CHOICE'];
        yield 'Range' => [61, new Assert\Range(min: 0, max: 60), 'OUT_OF_RANGE'];
        yield 'Positive' => [0, new Assert\Positive(), 'OUT_OF_RANGE'];
        yield 'PositiveOrZero' => [-1, new Assert\PositiveOrZero(), 'OUT_OF_RANGE'];
        yield 'Count' => [[1], new Assert\Count(min: 2), 'OUT_OF_RANGE'];
        yield 'Type' => ['1', new Assert\Type('integer'), 'INVALID_TYPE'];
        yield 'Unique' => [[1, 1], new Assert\Unique(), 'DUPLICATE'];
        yield 'any other constraint' => ['x', new Assert\Regex('/^\d+$/'), 'INVALID'];
        yield 'Sequentially reports the inner constraint' => ['', new Assert\Sequentially([new Assert\NotBlank(), new Assert\Email()]), 'REQUIRED'];
        yield 'All reports the inner constraint' => [[0], new Assert\All([new Assert\Positive()]), 'OUT_OF_RANGE'];
        yield 'Collection reports the inner constraint' => [['a' => 'x'], new Assert\Collection(['a' => new Assert\Type('integer')]), 'INVALID_TYPE'];
        yield 'a field Collection misses' => [[], new Assert\Collection(['a' => new Assert\NotNull()]), 'REQUIRED'];
        yield 'a field Collection does not expect' => [['b' => 1], new Assert\Collection([]), 'INVALID'];
        yield 'payload overrides the table' => ['nope', new Assert\Email(payload: ['code' => 'EMAIL_DOMAIN_NOT_ALLOWED']), 'EMAIL_DOMAIN_NOT_ALLOWED'];
    }

    /**
     * @param Constraint|list<Constraint> $constraint
     */
    #[DataProvider('constraints')]
    public function testMapsTheConstraintToItsCode(mixed $value, Constraint|array $constraint, string $code): void
    {
        $violations = Validation::createValidator()->validate($value, $constraint);

        self::assertCount(1, $violations);
        self::assertSame($code, ViolationCode::of($violations[0]));
    }

    public function testAValueTheSerializerCannotMapIsAnInvalidType(): void
    {
        $template = 'This value should be of type {{ type }}.';
        $violation = new ConstraintViolation('This value should be of type int.', $template, ['{{ type }}' => 'int'], null, 'startScore', null);

        self::assertSame('INVALID_TYPE', ViolationCode::of($violation));
    }

    public function testAViolationWithoutConstraintIsInvalid(): void
    {
        $violation = new ConstraintViolation('This attribute was not expected.', 'This attribute was not expected.', [], null, 'extra', null);

        self::assertSame('INVALID', ViolationCode::of($violation));
    }
}
