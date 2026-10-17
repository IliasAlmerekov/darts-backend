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
use App\Entity\User;
use App\Enum\GameStatus;
use App\Tests\Support\TestUserFactory;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Login success (GET /api/login/success and POST /api/login) for a player without a working
 * invitation: back to their current game, or the Profile when they have none (#105).
 */
final class LoginSuccessRedirectEndpointTest extends WebTestCase
{
    private const string USER_PREFIX = 'loginredirect';

    public function testPlayerWithoutCurrentGameLandsOnProfile(): void
    {
        $client = static::createClient();
        $player = $this->createUser('player', 'ROLE_PLAYER');
        $client->loginUser($player);

        $payload = $this->getLoginSuccess($client);

        self::assertSame('/playerprofile', parse_url($payload['redirect'], PHP_URL_PATH));
        self::assertArrayNotHasKey('gameId', $payload);
    }

    /**
     * @return array<string, array{GameStatus}>
     */
    public static function currentGameStatuses(): array
    {
        return [
            'lobby' => [GameStatus::Lobby],
            'started' => [GameStatus::Started],
        ];
    }

    #[DataProvider('currentGameStatuses')]
    public function testPlayerWithCurrentGameReturnsToIt(GameStatus $status): void
    {
        $client = static::createClient();
        $player = $this->createUser('player', 'ROLE_PLAYER');
        $gameId = $this->createGame($status, $player);
        $client->loginUser($player);

        $payload = $this->getLoginSuccess($client);

        self::assertSame('/joined', parse_url($payload['redirect'], PHP_URL_PATH));
        self::assertSame($gameId, $payload['gameId']);
    }

    /**
     * @return array<string, array{GameStatus}>
     */
    public static function endedGameStatuses(): array
    {
        return [
            'finished' => [GameStatus::Finished],
            'aborted' => [GameStatus::Aborted],
        ];
    }

    #[DataProvider('endedGameStatuses')]
    public function testPlayerWhoseGamesAllEndedLandsOnProfile(GameStatus $status): void
    {
        $client = static::createClient();
        $player = $this->createUser('player', 'ROLE_PLAYER');
        $this->createGame($status, $player);
        $client->loginUser($player);

        $payload = $this->getLoginSuccess($client);

        self::assertSame('/playerprofile', parse_url($payload['redirect'], PHP_URL_PATH));
        self::assertArrayNotHasKey('gameId', $payload);
    }

    public function testPlayerRemovedFromTheRoomLandsOnProfile(): void
    {
        $client = static::createClient();
        $admin = $this->createUser('admin', 'ROLE_ADMIN');
        $player = $this->createUser('player', 'ROLE_PLAYER');
        $gameId = $this->createGame(GameStatus::Lobby, $player);

        $client->loginUser($admin);
        $client->request(Request::METHOD_DELETE, sprintf('/api/room/%d?playerId=%d', $gameId, $player->getId()));
        self::assertResponseIsSuccessful((string) $client->getResponse()->getContent());

        $client->loginUser($player);
        $payload = $this->getLoginSuccess($client);

        self::assertSame('/playerprofile', parse_url($payload['redirect'], PHP_URL_PATH));
        self::assertArrayNotHasKey('gameId', $payload);
    }

    public function testPostLoginWithCurrentGameReturnsToIt(): void
    {
        $client = static::createClient();
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $player = $this->createUser('poster', 'ROLE_PLAYER');
        $player->setPassword($hasher->hashPassword($player, 'correct-password'));
        $gameId = $this->createGame(GameStatus::Lobby, $player);

        $client->request(Request::METHOD_POST, '/api/login', [
            '_username' => $player->getEmail(),
            '_password' => 'correct-password',
        ]);

        $payload = $this->decodeLoginSuccess($client);
        self::assertSame('/joined', parse_url($payload['redirect'], PHP_URL_PATH));
        self::assertSame($gameId, $payload['gameId']);
    }

    public function testFailedInvitationLandsOnProfileEvenWithACurrentGame(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $player = $this->createUser('player', 'ROLE_PLAYER');
        $this->createGame(GameStatus::Lobby, $player);
        $startedGameId = $this->createGame(GameStatus::Started, $this->createUser('other', 'ROLE_PLAYER'));
        $invitation = (new Invitation())->setUuid(Uuid::v4())->setGameId($startedGameId);
        $entityManager->persist($invitation);
        $entityManager->flush();

        $client->request(Request::METHOD_GET, sprintf('/api/invite/join/%s', $invitation->getUuid()));
        self::assertResponseStatusCodeSame(Response::HTTP_FOUND);
        $client->loginUser($player);
        $payload = $this->getLoginSuccess($client);

        self::assertSame('/playerprofile', parse_url($payload['redirect'], PHP_URL_PATH));
        self::assertSame('GAME_ALREADY_STARTED', $payload['invitationError']);
        self::assertArrayNotHasKey('gameId', $payload);
    }

    public function testAdminWithoutInvitationLandsOnStart(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('admin', 'ROLE_ADMIN'));

        $payload = $this->getLoginSuccess($client);

        self::assertSame('/start', parse_url($payload['redirect'], PHP_URL_PATH));
        self::assertArrayNotHasKey('gameId', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function getLoginSuccess(KernelBrowser $client): array
    {
        $client->request(Request::METHOD_GET, '/api/login/success');

        return $this->decodeLoginSuccess($client);
    }

    /**
     * Every login success response, whatever its target, disables nginx buffering.
     *
     * @return array<string, mixed>
     */
    private function decodeLoginSuccess(KernelBrowser $client): array
    {
        self::assertResponseStatusCodeSame(Response::HTTP_OK, (string) $client->getResponse()->getContent());
        self::assertResponseHeaderSame('X-Accel-Buffering', 'no');

        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertTrue($payload['success']);

        return $payload;
    }

    private function createUser(string $label, string $role): User
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $user = TestUserFactory::create($entityManager, self::USER_PREFIX, $label, [$role]);
        $entityManager->flush();

        return $user;
    }

    private function createGame(GameStatus $status, User $player): int
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $game = (new Game())
            ->setDate(new DateTime())
            ->setStartScore(301)
            ->setDoubleOut(false)
            ->setTripleOut(false)
            ->setStatus($status);
        $entityManager->persist($game);

        $seat = (new GamePlayers())
            ->setGame($game)
            ->setPlayer($player)
            ->setPosition(1)
            ->setScore(301)
            ->setIsWinner(false);
        $game->addGamePlayer($seat);
        $entityManager->persist($seat);
        $entityManager->flush();

        $gameId = $game->getGameId();
        self::assertNotNull($gameId);

        return $gameId;
    }
}
