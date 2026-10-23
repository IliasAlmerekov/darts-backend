<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Game;
use App\Entity\GamePlayers;
use App\Entity\Invitation;
use App\Enum\GameStatus;
use App\Tests\Support\TestUserFactory;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
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

        $invitation = (new Invitation())->setUuid(Uuid::v4())->setGame($game);
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

    public function testStaleInvitationAfterGameStartDoesNotBlockLoginSuccess(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $player = TestUserFactory::create($entityManager, self::USER_PREFIX, 'late', ['ROLE_PLAYER']);
        $game = $this->createGame($entityManager, GameStatus::Lobby);
        $invitation = $this->createInvitation($entityManager, $game);

        $client->request(Request::METHOD_GET, sprintf('/api/invite/join/%s', $invitation->getUuid()));
        self::assertResponseStatusCodeSame(Response::HTTP_FOUND);

        $game->setStatus(GameStatus::Started);
        $entityManager->flush();

        $client->loginUser($player);
        $this->assertLoginSucceedsWithInvitationError($client, 'GAME_ALREADY_STARTED');
    }

    public function testStaleInvitationForFullRoomDoesNotBlockLoginSuccess(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $player = TestUserFactory::create($entityManager, self::USER_PREFIX, 'overflow', ['ROLE_PLAYER']);
        $game = $this->createGame($entityManager, GameStatus::Lobby);
        $invitation = $this->createInvitation($entityManager, $game);

        $client->request(Request::METHOD_GET, sprintf('/api/invite/join/%s', $invitation->getUuid()));
        self::assertResponseStatusCodeSame(Response::HTTP_FOUND);

        for ($position = 1; $position <= 10; ++$position) {
            $member = TestUserFactory::create($entityManager, self::USER_PREFIX, 'member'.$position, ['ROLE_PLAYER']);
            $entityManager->persist(
                (new GamePlayers())
                    ->setGame($game)
                    ->setPlayer($member)
                    ->setPosition($position)
                    ->setDisplayNameSnapshot((string) $member->getUsername())
            );
        }
        $entityManager->flush();

        $client->loginUser($player);
        $this->assertLoginSucceedsWithInvitationError($client, 'GAME_ROOM_FULL');
    }

    public function testStaleInvitationForDeletedGameDoesNotBlockLoginSuccess(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $player = TestUserFactory::create($entityManager, self::USER_PREFIX, 'orphan', ['ROLE_PLAYER']);
        $game = $this->createGame($entityManager, GameStatus::Lobby);
        $invitation = $this->createInvitation($entityManager, $game);

        $client->request(Request::METHOD_GET, sprintf('/api/invite/join/%s', $invitation->getUuid()));
        self::assertResponseStatusCodeSame(Response::HTTP_FOUND);

        $entityManager->remove($game);
        $entityManager->flush();

        $client->loginUser($player);
        $this->assertLoginSucceedsWithInvitationError($client, 'GAME_NOT_FOUND');
    }

    public function testInvitationToStartedGameJoinedBeforeLoginReportsGameAlreadyStarted(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $player = TestUserFactory::create($entityManager, self::USER_PREFIX, 'scanner', ['ROLE_PLAYER']);
        $game = $this->createGame($entityManager, GameStatus::Started);
        $invitation = $this->createInvitation($entityManager, $game);

        $client->request(Request::METHOD_GET, sprintf('/api/invite/join/%s', $invitation->getUuid()));
        self::assertResponseStatusCodeSame(Response::HTTP_FOUND);
        self::assertSame((string) $invitation->getUuid(), $client->getRequest()->getSession()->get('invitation_uuid'));

        $client->loginUser($player);
        $this->assertLoginSucceedsWithInvitationError($client, 'GAME_ALREADY_STARTED');
    }

    public function testInvitationToAbortedGameReportsJoinNotAllowed(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $player = TestUserFactory::create($entityManager, self::USER_PREFIX, 'late', ['ROLE_PLAYER']);
        $game = $this->createGame($entityManager, GameStatus::Aborted);
        $invitation = $this->createInvitation($entityManager, $game);

        $client->request(Request::METHOD_GET, sprintf('/api/invite/join/%s', $invitation->getUuid()));
        self::assertResponseStatusCodeSame(Response::HTTP_FOUND);

        $client->loginUser($player);
        $this->assertLoginSucceedsWithInvitationError($client, 'GAME_JOIN_NOT_ALLOWED');
    }

    public function testUnknownInvitationUuidReturnsNotFound(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_GET, sprintf('/api/invite/join/%s', Uuid::v4()));

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testPostLoginWithStaleInvitationReturnsUserPayloadAndInvitationError(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $player = TestUserFactory::create($entityManager, self::USER_PREFIX, 'poster', ['ROLE_PLAYER']);
        $player->setPassword($hasher->hashPassword($player, 'correct-password'));
        $game = $this->createGame($entityManager, GameStatus::Lobby);
        $invitation = $this->createInvitation($entityManager, $game);

        $client->request(Request::METHOD_GET, sprintf('/api/invite/join/%s', $invitation->getUuid()));
        self::assertResponseStatusCodeSame(Response::HTTP_FOUND);

        $game->setStatus(GameStatus::Started);
        $entityManager->flush();

        $client->request(Request::METHOD_POST, '/api/login', [
            '_username' => $player->getEmail(),
            '_password' => 'correct-password',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_OK, (string) $client->getResponse()->getContent());
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($payload['success']);
        self::assertSame($player->getId(), $payload['id']);
        self::assertSame('/playerprofile', parse_url($payload['redirect'], PHP_URL_PATH));
        self::assertSame('GAME_ALREADY_STARTED', $payload['invitationError']);
        self::assertArrayNotHasKey('gameId', $payload);

        $client->request(Request::METHOD_GET, '/api/login/success');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('invitationError', $payload);
    }

    public function testAdminLoginIgnoresInvitationInSession(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $admin = TestUserFactory::create($entityManager, self::USER_PREFIX, 'admin', ['ROLE_ADMIN']);
        $game = $this->createGame($entityManager, GameStatus::Started);
        $invitation = $this->createInvitation($entityManager, $game);

        $client->request(Request::METHOD_GET, sprintf('/api/invite/join/%s', $invitation->getUuid()));
        $client->loginUser($admin);
        $client->request(Request::METHOD_GET, '/api/login/success');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('/start', parse_url($payload['redirect'], PHP_URL_PATH));
        self::assertArrayNotHasKey('invitationError', $payload);
        self::assertArrayNotHasKey('gameId', $payload);
    }

    public function testProcessInvitationForStartedGameFailsAndClearsSession(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $player = TestUserFactory::create($entityManager, self::USER_PREFIX, 'proc', ['ROLE_PLAYER']);
        $game = $this->createGame($entityManager, GameStatus::Started);
        $invitation = $this->createInvitation($entityManager, $game);

        $client->request(Request::METHOD_GET, sprintf('/api/invite/join/%s', $invitation->getUuid()));
        $client->loginUser($player);
        $client->request(Request::METHOD_POST, '/api/invite/process');

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('GAME_ALREADY_STARTED', $payload['error']);
        self::assertNull($client->getRequest()->getSession()->get('invitation_uuid'));
        self::assertNull($client->getRequest()->getSession()->get('game_id'));
    }

    private function createGame(EntityManagerInterface $entityManager, GameStatus $status): Game
    {
        $game = (new Game())
            ->setDate(new DateTime())
            ->setStartScore(301)
            ->setDoubleOut(false)
            ->setTripleOut(false)
            ->setStatus($status);
        $entityManager->persist($game);
        $entityManager->flush();

        return $game;
    }

    private function createInvitation(EntityManagerInterface $entityManager, Game $game): Invitation
    {
        $invitation = (new Invitation())->setUuid(Uuid::v4())->setGame($game);
        $entityManager->persist($invitation);
        $entityManager->flush();

        return $invitation;
    }

    private function assertLoginSucceedsWithInvitationError(KernelBrowser $client, string $errorCode): void
    {
        $client->request(Request::METHOD_GET, '/api/login/success');
        self::assertResponseStatusCodeSame(Response::HTTP_OK, (string) $client->getResponse()->getContent());
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($payload['success']);
        self::assertSame($errorCode, $payload['invitationError']);
        self::assertSame('/playerprofile', parse_url($payload['redirect'], PHP_URL_PATH));
        self::assertArrayNotHasKey('gameId', $payload);

        // The failed join consumed the invitation: the next call is a plain login success.
        $client->request(Request::METHOD_GET, '/api/login/success');
        self::assertResponseStatusCodeSame(Response::HTTP_OK, (string) $client->getResponse()->getContent());
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($payload['success']);
        self::assertArrayNotHasKey('invitationError', $payload);
        self::assertNull($client->getRequest()->getSession()->get('invitation_uuid'));
        self::assertNull($client->getRequest()->getSession()->get('game_id'));
    }
}
