<?php

namespace App\Tests\Dto;

use App\Dto\StartGameRequest;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class StartGameRequestTest extends KernelTestCase
{
    private SerializerInterface $serializer;
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->serializer = static::getContainer()->get(SerializerInterface::class);
        $this->validator = static::getContainer()->get(ValidatorInterface::class);
    }

    public function testDeserializeValidStartGameRequest(): void
    {
        $json = '{
        "startScore": 301,
        "doubleOut": true,
        "tripleOut": false,
        "playerPositions": [1, 2, 3, 4]
    }';
        $dto = $this->serializer->deserialize($json, StartGameRequest::class, 'json');

        $this->assertInstanceOf(StartGameRequest::class, $dto);
        $this->assertSame(301, $dto->startScore);
        $this->assertTrue($dto->doubleOut);
        $this->assertFalse($dto->tripleOut);
        $this->assertSame([1, 2, 3, 4], $dto->playerPositions);

        $errors = $this->validator->validate($dto);
        $this->assertCount(0, $errors, 'DTO sollte valide sein');
    }

    public function testDeserializeWithEmptyJson(): void
    {
        $json = '{}';

        $dto = $this->serializer->deserialize($json, StartGameRequest::class, 'json');

        $this->assertInstanceOf(StartGameRequest::class, $dto);
        $this->assertNull($dto->startScore);
        $this->assertNull($dto->doubleOut);
        $this->assertNull($dto->tripleOut);
        $this->assertNull($dto->playerPositions);

        $errors = $this->validator->validate($dto);
        $this->assertCount(0, $errors);
    }

    public function testValidationFailsForInvalidStartScore(): void
    {
        $json = '{
        "startScore": 999
    }';

        $dto = $this->serializer->deserialize($json, StartGameRequest::class, 'json');

        $errors = $this->validator->validate($dto);
        $this->assertGreaterThan(0, count($errors));

        $errorMessage = $errors[0]->getMessage();
        $this->assertStringContainsString('choice', strtolower($errorMessage));
    }

    public function testDeserializationForInvalidBoolean(): void
    {
        $json = '{
        "doubleOut": "yes"
    }';
        $this->expectException(NotNormalizableValueException::class);
        $this->expectExceptionMessage('The type of the "doubleOut" attribute');

        $this->serializer->deserialize($json, StartGameRequest::class, 'json');
    }

    public function testValidationFailsForTooFewPlayers(): void
    {
        $json = '{
        "playerPositions": [1]
    }';
        $dto = $this->serializer->deserialize($json, StartGameRequest::class, 'json');

        $errors = $this->validator->validate($dto);
        $this->assertGreaterThan(0, count($errors));

        $errorMessage = $errors[0]->getMessage();
        $this->assertStringContainsString('2 elements or more', $errorMessage);
    }

}
