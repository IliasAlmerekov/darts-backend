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

/**
 * GET /api/login/success for a player without an invitation: back to their current game,
 * or the Profile when they have none (#105).
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

    /**
     * @return array<string, mixed>
     */
    private function getLoginSuccess(KernelBrowser $client): array
    {
        $client->request(Request::METHOD_GET, '/api/login/success');
        self::assertResponseStatusCodeSame(Response::HTTP_OK, (string) $client->getResponse()->getContent());

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
