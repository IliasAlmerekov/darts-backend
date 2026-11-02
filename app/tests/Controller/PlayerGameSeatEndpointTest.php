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
use App\Enum\OutRule;
use App\Tests\Support\TestUserFactory;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/player/games/{gameId}/seat: the game's status and whether the player still holds a seat in it.
 */
final class PlayerGameSeatEndpointTest extends WebTestCase
{
    private const string USER_PREFIX = 'gameseat';

    public function testPlayerSeatedInLobbyGetsSeatedTrue(): void
    {
        $client = static::createClient();
        $player = $this->createUser('player', 'ROLE_PLAYER');
        $gameId = $this->createGame(GameStatus::Lobby, $player);
        $client->loginUser($player);

        self::assertSame(['gameId' => $gameId, 'status' => 'lobby', 'seated' => true], $this->getSeat($client, $gameId));
    }

    public function testPlayerSeatedInStartedGameGetsSeatedTrue(): void
    {
        $client = static::createClient();
        $player = $this->createUser('player', 'ROLE_PLAYER');
        $gameId = $this->createGame(GameStatus::Started, $player);
        $client->loginUser($player);

        self::assertSame(['gameId' => $gameId, 'status' => 'started', 'seated' => true], $this->getSeat($client, $gameId));
    }

    public function testPlayerRemovedFromLobbyGetsSeatedFalse(): void
    {
        $client = static::createClient();
        $admin = $this->createUser('admin', 'ROLE_ADMIN');
        $player = $this->createUser('player', 'ROLE_PLAYER');
        $gameId = $this->createGame(GameStatus::Lobby, $player);

        $client->loginUser($admin);
        $client->request(Request::METHOD_DELETE, sprintf('/api/room/%d?playerId=%d', $gameId, $player->getId()));
        self::assertResponseIsSuccessful((string) $client->getResponse()->getContent());

        $client->loginUser($player);

        self::assertSame(['gameId' => $gameId, 'status' => 'lobby', 'seated' => false], $this->getSeat($client, $gameId));
    }

    #[DataProvider('closedGames')]
    public function testClosedGameReportsItsStatusWhetherOrNotThePlayerSat(GameStatus $status, bool $seated): void
    {
        $client = static::createClient();
        $player = $this->createUser('player', 'ROLE_PLAYER');
        $gameId = $this->createGame($status, $seated ? $player : $this->createUser('other', 'ROLE_PLAYER'));
        $client->loginUser($player);

        self::assertSame(['gameId' => $gameId, 'status' => $status->value, 'seated' => $seated], $this->getSeat($client, $gameId));
    }

    /**
     * @return array<string, array{GameStatus, bool}>
     */
    public static function closedGames(): array
    {
        return [
            'finished, seated' => [GameStatus::Finished, true],
            'finished, not seated' => [GameStatus::Finished, false],
            'aborted, seated' => [GameStatus::Aborted, true],
            'aborted, not seated' => [GameStatus::Aborted, false],
        ];
    }

    public function testUnknownGameGets404WithApiError(): void
    {
        $client = static::createClient();
        $player = $this->createUser('player', 'ROLE_PLAYER');
        $gameId = $this->createGame(GameStatus::Lobby, null);
        $client->loginUser($player);

        $client->request(Request::METHOD_GET, $this->uri($gameId + 1000));

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertFalse($body['success']);
        self::assertSame('GAME_NOT_FOUND', $body['error']);
    }

    public function testAnonymousGets401(): void
    {
        $client = static::createClient();
        $gameId = $this->createGame(GameStatus::Lobby, null);

        $client->request(Request::METHOD_GET, $this->uri($gameId));

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testAdminGets403(): void
    {
        $client = static::createClient();
        $gameId = $this->createGame(GameStatus::Lobby, null);
        $client->loginUser($this->createUser('admin', 'ROLE_ADMIN'));

        $client->request(Request::METHOD_GET, $this->uri($gameId));

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    /**
     * Asserts 200 and exactly the three documented keys, so nothing about other players leaks.
     *
     * @return array<string, mixed>
     */
    private function getSeat(KernelBrowser $client, int $gameId): array
    {
        $client->request(Request::METHOD_GET, $this->uri($gameId));
        self::assertResponseStatusCodeSame(Response::HTTP_OK, (string) $client->getResponse()->getContent());

        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame(['gameId', 'status', 'seated'], array_keys($body));

        return $body;
    }

    private function uri(int $gameId): string
    {
        return sprintf('/api/player/games/%d/seat', $gameId);
    }

    private function createUser(string $label, string $role): User
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $user = TestUserFactory::create($entityManager, self::USER_PREFIX, $label, [$role]);
        $entityManager->flush();

        return $user;
    }

    private function createGame(GameStatus $status, ?User $player): int
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $game = (new Game())
            ->setDate(new DateTime())
            ->setStartScore(301)
            ->setOutRule(OutRule::Single)
            ->setStatus($status);
        $entityManager->persist($game);

        if (null !== $player) {
            $seat = (new GamePlayers())
                ->setGame($game)
                ->setPlayer($player)
                ->setPosition(1)
                ->setScore(301)
                ->setIsWinner(false);
            $game->addGamePlayer($seat);
            $entityManager->persist($seat);
        }
        $entityManager->flush();

        $gameId = $game->getGameId();
        self::assertNotNull($gameId);

        return $gameId;
    }
}
