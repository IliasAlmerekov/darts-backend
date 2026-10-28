<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\Support\PayloadCode\PayloadCodeKernel;
use App\Tests\Support\TestUserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * A constraint with `payload: ['code' => ...]` reports that code instead of the table's
 * through the real request payload resolver and error controller (ADR 0006).
 */
final class RequestValidationPayloadCodeEndpointTest extends WebTestCase
{
    #[\Override]
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new PayloadCodeKernel('test', true);
    }

    public function testConstraintPayloadCodeOverridesTheTable(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $admin = TestUserFactory::create($entityManager, 'payloadcode', 'admin', ['ROLE_ADMIN']);
        $entityManager->flush();
        $client->loginUser($admin);

        $client->request(Request::METHOD_POST, PayloadCodeKernel::PATH, [], [], ['CONTENT_TYPE' => 'application/json'], '{"email": "nope"}');

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, (string) $client->getResponse()->getContent());
        self::assertSame(
            [
                'success' => false,
                'error' => 'REQUEST_VALIDATION_FAILED',
                'message' => 'The request body failed validation.',
                'violations' => [
                    ['field' => 'email', 'code' => 'EMAIL_DOMAIN_NOT_ALLOWED', 'message' => 'This value is not a valid email address.'],
                ],
            ],
            json_decode((string) $client->getResponse()->getContent(), true),
        );
    }
}
