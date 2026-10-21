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
use App\Entity\Round;
use App\Entity\RoundThrows;
use App\Entity\User;
use App\Enum\GameStatus;
use App\Tests\Support\TestUserFactory;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/player/profile: the logged-in player's statistics and their 10 most recent finished games.
 */
final class PlayerProfileEndpointTest extends WebTestCase
{
    private const string USER_PREFIX = 'profile';
    private const string URI = '/api/player/profile';

    public function testPlayerWithoutFinishedGameGetsEmptyProfile(): void
    {
        $client = static::createClient();
        $player = $this->createUser('player', 'ROLE_PLAYER');
        $this->createGame(GameStatus::Lobby, [$player]);
        $client->loginUser($player);

        self::assertSame(
            ['stats' => ['gamesPlayed' => 0, 'scoreAverage' => null], 'recentGames' => [], 'registeredAt' => null],
            $this->getProfile($client)
        );
    }

    public function testReturnsTheRegistrationDateAsAtomTimestamp(): void
    {
        $client = static::createClient();
        $registeredAt = new DateTimeImmutable('2026-03-02 08:15:00');
        $player = $this->createUser('player', 'ROLE_PLAYER')->setRegisteredAt($registeredAt);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $client->loginUser($player);

        self::assertSame($registeredAt->format(DateTimeInterface::ATOM), $this->getProfile($client)['registeredAt']);
    }

    public function testStatsEqualThePlayersRowInAdminPlayerStats(): void
    {
        $client = static::createClient();
        $admin = $this->createUser('admin', 'ROLE_ADMIN');
        $player = $this->createUser('player', 'ROLE_PLAYER');
        $other = $this->createUser('other', 'ROLE_PLAYER');
        $first = $this->createGame(GameStatus::Finished, [$player, $other]);
        $this->recordRound($first, 1, [[$player, [20, 40]], [$other, [5]]]);
        $this->recordRound($first, 2, [[$player, [30]]]);
        $second = $this->createGame(GameStatus::Finished, [$player]);
        $this->recordRound($second, 1, [[$player, [60]]]);
        $this->recordRound($second, 2, [[$player, [57]]], finished: false);

        $client->loginUser($player);
        $stats = $this->getProfile($client)['stats'];

        self::assertSame(['gamesPlayed' => 2, 'scoreAverage' => 50.0], $stats);

        $client->loginUser($admin);
        $client->request(Request::METHOD_GET, '/api/players/stats?limit=100');
        self::assertResponseIsSuccessful();
        $adminStats = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($adminStats);
        $rows = array_values(array_filter(
            $adminStats['items'],
            static fn(array $row): bool => $row['playerId'] === $player->getId()
        ));
        self::assertCount(1, $rows);
        self::assertSame($rows[0]['gamesPlayed'], $stats['gamesPlayed']);
        self::assertSame($rows[0]['scoreAverage'], $stats['scoreAverage']);
    }

    public function testFinishedGameListsOwnResultAndParticipantsByPlacement(): void
    {
        $client = static::createClient();
        $admin = $this->createUser('admin', 'ROLE_ADMIN');
        $player = $this->createUser('player', 'ROLE_PLAYER')->setDisplayName('Ilias');
        $mia = $this->createUser('mia', 'ROLE_PLAYER')->setDisplayName('Mia');
        $tom = $this->createUser('tom', 'ROLE_PLAYER')->setDisplayName('Tom')->setIsGuest(true);
        $game = $this->createGame(GameStatus::Finished, [$player, $mia, $tom]);
        $this->recordRound($game, 1, [[$player, [20, 40]], [$mia, [60]], [$tom, [10]]]);
        $this->recordRound($game, 2, [[$player, [30]], [$mia, [100, 41]]]);
        $finishedAt = new DateTimeImmutable('2026-10-18 17:42:00');
        $this->settle($game, $finishedAt, [[$mia, 1, 0], [$player, 2, 40], [$tom, 3, 100]]);

        $client->loginUser($player);

        self::assertSame([[
            'gameId' => $game->getGameId(),
            'finishedAt' => $finishedAt->format(DateTimeInterface::ATOM),
            'placement' => 2,
            'roundAverage' => 45.0,
            'participantsCount' => 3,
            'participants' => [
                ['playerId' => $mia->getId(), 'displayName' => 'Mia', 'placement' => 1, 'isSelf' => false],
                ['playerId' => $player->getId(), 'displayName' => 'Ilias', 'placement' => 2, 'isSelf' => true],
                ['playerId' => null, 'displayName' => 'Tom', 'placement' => 3, 'isSelf' => false],
            ],
        ]], $this->getProfile($client)['recentGames']);

        $client->loginUser($admin);
        $client->request(Request::METHOD_GET, sprintf('/api/games/%d', $game->getGameId()));
        self::assertResponseIsSuccessful();
        $summary = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($summary);
        $own = array_values(array_filter(
            $summary['finishedPlayers'],
            static fn(array $row): bool => $row['playerId'] === $player->getId()
        ));
        self::assertCount(1, $own);
        self::assertSame(2, $own[0]['position']);
        self::assertSame(45.0, $own[0]['roundAverage']);
    }

    public function testOnlyFinishedGamesThePlayerTookPartInAppear(): void
    {
        $client = static::createClient();
        $player = $this->createUser('player', 'ROLE_PLAYER');
        $other = $this->createUser('other', 'ROLE_PLAYER');
        foreach ([GameStatus::Lobby, GameStatus::Started, GameStatus::Aborted] as $status) {
            $this->createGame($status, [$player, $other]);
        }
        $this->settle($this->createGame(GameStatus::Finished, [$other]), new DateTimeImmutable(), [[$other, 1, 0]]);
        $own = $this->createGame(GameStatus::Finished, [$player, $other]);
        $this->settle($own, new DateTimeImmutable(), [[$player, 1, 0], [$other, 2, 120]]);
        $client->loginUser($player);

        $recentGames = $this->getProfile($client)['recentGames'];

        self::assertSame([$own->getGameId()], array_column($recentGames, 'gameId'));
    }

    /**
     * Per the issue, recentGames lists every finished game with a seat, while stats count only
     * games with a finished round, as the admin list does. The two can differ.
     */
    public function testFinishedGameWithoutOwnThrowAppearsButIsNotCountedInStats(): void
    {
        $client = static::createClient();
        $player = $this->createUser('player', 'ROLE_PLAYER');
        $other = $this->createUser('other', 'ROLE_PLAYER');
        $game = $this->createGame(GameStatus::Finished, [$player, $other]);
        $this->recordRound($game, 1, [[$other, [60]]]);
        $this->settle($game, new DateTimeImmutable(), [[$other, 1, 0], [$player, 2, 301]]);
        $client->loginUser($player);

        $profile = $this->getProfile($client);

        self::assertSame(['gamesPlayed' => 0, 'scoreAverage' => null], $profile['stats']);
        self::assertSame([$game->getGameId()], array_column($profile['recentGames'], 'gameId'));
        self::assertSame(0.0, $profile['recentGames'][0]['roundAverage']);
    }

    public function testReturnsTheTenNewestFinishedGamesNewestFirstByFinishedAtOrDate(): void
    {
        $client = static::createClient();
        $player = $this->createUser('player', 'ROLE_PLAYER');
        $gameIdsByDay = [];
        foreach (range(1, 11) as $day) {
            $game = $this->createGame(GameStatus::Finished, [$player]);
            $this->settle($game, new DateTimeImmutable(sprintf('2026-10-%02d 12:00:00', $day)), [[$player, 1, 0]]);
            $gameIdsByDay[$day] = $game->getGameId();
        }
        $withoutFinishedAt = $this->createGame(GameStatus::Finished, [$player], new DateTime('2026-10-09'));
        $this->settle($withoutFinishedAt, null, [[$player, 1, 0]]);
        $client->loginUser($player);

        $recentGames = $this->getProfile($client)['recentGames'];

        self::assertSame([
            $gameIdsByDay[11],
            $gameIdsByDay[10],
            $gameIdsByDay[9],
            $withoutFinishedAt->getGameId(),
            $gameIdsByDay[8],
            $gameIdsByDay[7],
            $gameIdsByDay[6],
            $gameIdsByDay[5],
            $gameIdsByDay[4],
            $gameIdsByDay[3],
        ], array_column($recentGames, 'gameId'));
        self::assertNull($recentGames[3]['finishedAt']);
    }

    public function testQueryCountDoesNotGrowWithTheNumberOfGames(): void
    {
        static::createClient();
        $player = $this->createUser('player', 'ROLE_PLAYER');
        $other = $this->createUser('other', 'ROLE_PLAYER');
        $this->createPlayedGame($player, $other);
        $queriesForOneGame = $this->countProfileQueries($player);

        foreach (range(2, 10) as $ignored) {
            $this->createPlayedGame($player, $other);
        }
        $queriesForTenGames = $this->countProfileQueries($player);

        self::assertSame($queriesForOneGame, $queriesForTenGames);
    }

    public function testAnonymousGets401(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_GET, self::URI);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testAdminGets403(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('admin', 'ROLE_ADMIN'));

        $client->request(Request::METHOD_GET, self::URI);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    /**
     * @return array<string, mixed>
     */
    private function getProfile(KernelBrowser $client): array
    {
        $client->request(Request::METHOD_GET, self::URI);
        self::assertResponseStatusCodeSame(Response::HTTP_OK, (string) $client->getResponse()->getContent());

        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }

    /**
     * Requests the Profile on a fresh kernel, so the profiler counts only that request's queries.
     */
    private function countProfileQueries(User $player): int
    {
        static::ensureKernelShutdown();
        $client = static::createClient();
        $client->loginUser($player);
        $client->enableProfiler();
        $client->request(Request::METHOD_GET, self::URI);
        self::assertResponseIsSuccessful();
        $profile = $client->getProfile();
        self::assertNotFalse($profile);

        /** @var \Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector $dbCollector */
        $dbCollector = $profile->getCollector('db');

        return $dbCollector->getQueryCount();
    }

    private function createPlayedGame(User $player, User $other): void
    {
        // The users may come from a kernel that countProfileQueries() has shut down.
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $player = $entityManager->find(User::class, $player->getId());
        $other = $entityManager->find(User::class, $other->getId());
        self::assertNotNull($player);
        self::assertNotNull($other);
        $game = $this->createGame(GameStatus::Finished, [$player, $other]);
        $this->recordRound($game, 1, [[$player, [60]], [$other, [20]]]);
        $this->settle($game, new DateTimeImmutable(), [[$player, 1, 0], [$other, 2, 281]]);
    }

    private function createUser(string $label, string $role): User
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $user = TestUserFactory::create($entityManager, self::USER_PREFIX, $label, [$role]);
        $entityManager->flush();

        return $user;
    }

    /**
     * @param list<User> $participants seated in this order
     */
    private function createGame(GameStatus $status, array $participants, ?DateTime $date = null): Game
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $game = (new Game())
            ->setDate($date ?? new DateTime())
            ->setStartScore(301)
            ->setStatus($status);
        $entityManager->persist($game);

        foreach ($participants as $index => $participant) {
            $seat = (new GamePlayers())
                ->setGame($game)
                ->setPlayer($participant)
                ->setPosition($index + 1)
                ->setScore(301)
                ->setIsWinner(false)
                ->setDisplayNameSnapshot((string) $participant->getDisplayName());
            $game->addGamePlayer($seat);
            $entityManager->persist($seat);
        }
        $entityManager->flush();

        return $game;
    }

    /**
     * Marks the game finished with the given results.
     *
     * @param list<array{User, int, int}> $results participant, placement, remaining score
     */
    private function settle(Game $game, ?DateTimeImmutable $finishedAt, array $results): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        foreach ($results as [$participant, $placement, $score]) {
            foreach ($game->getGamePlayers() as $seat) {
                if ($seat->getPlayer() === $participant) {
                    $seat->setPosition($placement)->setScore($score)->setIsWinner(1 === $placement);
                }
            }
            if (1 === $placement) {
                $game->setWinner($participant);
            }
        }
        $game->setFinishedAt($finishedAt);
        $entityManager->flush();
    }

    /**
     * @param list<array{User, list<int>}> $throwsByParticipant thrown values per participant
     */
    private function recordRound(Game $game, int $roundNumber, array $throwsByParticipant, bool $finished = true): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $round = (new Round())
            ->setGame($game)
            ->setRoundNumber($roundNumber)
            ->setStartedAt(new DateTimeImmutable('-1 minute'));
        if ($finished) {
            $round->setFinishedAt(new DateTimeImmutable());
        }
        $entityManager->persist($round);

        foreach ($throwsByParticipant as [$participant, $values]) {
            foreach ($values as $index => $value) {
                $entityManager->persist((new RoundThrows())
                    ->setGame($game)
                    ->setRound($round)
                    ->setPlayer($participant)
                    ->setThrowNumber($index + 1)
                    ->setValue($value)
                    ->setIsBust(false)
                    ->setScore(0)
                    ->setTimestamp(new DateTimeImmutable()));
            }
        }
        $entityManager->flush();
    }
}
