<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Game;
use App\Entity\User;
use App\Enum\GameStatus;
use App\Tests\Support\TestUserFactory;
use DateTime;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/tablet/game: the tablet game and the last finished game, for the admin.
 */
final class TabletGameEndpointTest extends WebTestCase
{
    private const string USER_PREFIX = 'tabletgame';
    private const string URI = '/api/tablet/game';

    public function testOpenLobbyIsTheTabletGame(): void
    {
        $client = $this->createAdminClient();
        $lobbyId = $this->createGame(GameStatus::Lobby);

        self::assertSame(
            ['game' => ['id' => $lobbyId, 'status' => 'lobby'], 'lastFinishedGameId' => null],
            $this->getTabletGame($client),
        );
    }

    public function testStartedGameIsTheTabletGame(): void
    {
        $client = $this->createAdminClient();
        $finishedId = $this->createGame(GameStatus::Finished, new DateTimeImmutable('-1 hour'));
        $startedId = $this->createGame(GameStatus::Started);

        self::assertSame(
            ['game' => ['id' => $startedId, 'status' => 'started'], 'lastFinishedGameId' => $finishedId],
            $this->getTabletGame($client),
        );
    }

    public function testNoLobbyOrStartedGameGivesNullGame(): void
    {
        $client = $this->createAdminClient();
        $this->createGame(GameStatus::Aborted);
        $finishedId = $this->createGame(GameStatus::Finished, new DateTimeImmutable('-1 hour'));

        self::assertSame(['game' => null, 'lastFinishedGameId' => $finishedId], $this->getTabletGame($client));
    }

    public function testNoGameAtAllGivesNulls(): void
    {
        $client = $this->createAdminClient();

        self::assertSame(['game' => null, 'lastFinishedGameId' => null], $this->getTabletGame($client));
    }

    public function testGreatestGameIdWinsWhenMoreThanOneGameMatches(): void
    {
        $client = $this->createAdminClient();
        $this->createGame(GameStatus::Lobby);
        $startedId = $this->createGame(GameStatus::Started);

        self::assertSame(
            ['game' => ['id' => $startedId, 'status' => 'started'], 'lastFinishedGameId' => null],
            $this->getTabletGame($client),
        );
    }

    public function testLastFinishedGameFollowsFinishedAtNotGameId(): void
    {
        $client = $this->createAdminClient();
        $refinishedId = $this->createGame(GameStatus::Finished, new DateTimeImmutable('-5 minutes'));
        $this->createGame(GameStatus::Finished, new DateTimeImmutable('-1 hour'));

        self::assertSame(['game' => null, 'lastFinishedGameId' => $refinishedId], $this->getTabletGame($client));
    }

    public function testAnonymousGets401(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_GET, self::URI);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testPlayerGets403(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('player', 'ROLE_PLAYER'));

        $client->request(Request::METHOD_GET, self::URI);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    /**
     * Leaves the test database without lobby, started or finished games, so each test sees
     * only the games it creates. The DAMA transaction rolls the update back.
     */
    private function createAdminClient(): KernelBrowser
    {
        $client = static::createClient();
        static::getContainer()->get(EntityManagerInterface::class)
            ->createQuery('UPDATE App\Entity\Game g SET g.status = :aborted WHERE g.status IN (:statuses)')
            ->setParameter('aborted', GameStatus::Aborted)
            ->setParameter('statuses', [GameStatus::Lobby, GameStatus::Started, GameStatus::Finished])
            ->execute();
        $client->loginUser($this->createUser('admin', 'ROLE_ADMIN'));

        return $client;
    }

    /**
     * @return array<string, mixed>
     */
    private function getTabletGame(KernelBrowser $client): array
    {
        $client->request(Request::METHOD_GET, self::URI);
        self::assertResponseStatusCodeSame(Response::HTTP_OK, (string) $client->getResponse()->getContent());

        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }

    private function createUser(string $label, string $role): User
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $user = TestUserFactory::create($entityManager, self::USER_PREFIX, $label, [$role]);
        $entityManager->flush();

        return $user;
    }

    private function createGame(GameStatus $status, ?DateTimeImmutable $finishedAt = null): int
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $game = (new Game())
            ->setDate(new DateTime())
            ->setStartScore(301)
            ->setDoubleOut(false)
            ->setTripleOut(false)
            ->setStatus($status)
            ->setFinishedAt($finishedAt);
        $entityManager->persist($game);
        $entityManager->flush();

        $gameId = $game->getGameId();
        self::assertNotNull($gameId);

        return $gameId;
    }
}
