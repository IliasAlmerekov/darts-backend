<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Game;
use App\Entity\Invitation;
use App\Enum\GameStatus;
use App\Tests\Support\TestUserFactory;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class LoginSuccessInvitationEndpointTest extends WebTestCase
{
    private const string USER_PREFIX = 'loginsuccess';

    public function testInvitationLoginReturnsUserPayloadAndGameId(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $player = TestUserFactory::create($entityManager, self::USER_PREFIX, 'player', ['ROLE_PLAYER']);
        $game = (new Game())
            ->setDate(new DateTime())
            ->setStartScore(301)
            ->setDoubleOut(false)
            ->setTripleOut(false)
            ->setStatus(GameStatus::Lobby);
        $entityManager->persist($game);
        $entityManager->flush();
        $gameId = (int) $game->getGameId();

        $invitation = (new Invitation())->setUuid(Uuid::v4())->setGameId($gameId);
        $entityManager->persist($invitation);
        $entityManager->flush();

        $client->request(Request::METHOD_GET, sprintf('/api/invite/join/%s', $invitation->getUuid()));
        self::assertResponseStatusCodeSame(Response::HTTP_FOUND);

        $client->loginUser($player);
        $client->request(Request::METHOD_GET, '/api/login/success');

        self::assertResponseStatusCodeSame(Response::HTTP_OK, (string) $client->getResponse()->getContent());
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($payload['success']);
        self::assertSame($player->getStoredRoles(), $payload['roles']);
        self::assertSame($player->getId(), $payload['id']);
        self::assertSame($player->getEmail(), $payload['email']);
        self::assertSame($player->getUsername(), $payload['username']);
        self::assertSame($gameId, $payload['gameId']);
        self::assertSame('/joined', parse_url($payload['redirect'], PHP_URL_PATH));
        self::assertArrayNotHasKey('profile', $payload);
    }
}
