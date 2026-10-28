<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\Support\TestUserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class RematchUnknownGameEndpointTest extends WebTestCase
{
    public function testRematchOfUnknownGameAnswers404GameNotFound(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $admin = TestUserFactory::create($entityManager, 'rematchmissing', 'admin', ['ROLE_ADMIN']);
        $entityManager->flush();

        $client->loginUser($admin);
        $client->request(Request::METHOD_POST, '/api/room/2147483000/rematch');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, (string) $client->getResponse()->getContent());
        self::assertSame(
            '{"success":false,"error":"GAME_NOT_FOUND","message":"Game not found"}',
            $client->getResponse()->getContent(),
        );
    }
}
