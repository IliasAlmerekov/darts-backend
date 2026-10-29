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
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * `GET /api/players/stats` sorts every player with statistics before it cuts a page, so the
 * pages of one sort read as one continuous list.
 */
final class PlayerStatsSortEndpointTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private User $admin;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->admin = TestUserFactory::create($this->entityManager, 'statssort', 'admin', ['ROLE_ADMIN']);
        $this->entityManager->flush();
    }

    public function testSortByNameAscendingContinuesAcrossPages(): void
    {
        $players = $this->playFinishedGame([['Sort47 A', '20'], ['Sort47 B', '19'], ['Sort47 C', '18'], ['Sort47 D', '17'], ['Sort47 E', '16']]);

        self::assertSame(
            ['Sort47 A', 'Sort47 B', 'Sort47 C', 'Sort47 D', 'Sort47 E'],
            $this->namesOf($players, $this->allPages('name:asc'))
        );
    }

    public function testSortByNameDescendingReturnsTheReverseOrder(): void
    {
        $players = $this->playFinishedGame([['Sort47 A', '20'], ['Sort47 B', '19'], ['Sort47 C', '18'], ['Sort47 D', '17'], ['Sort47 E', '16']]);

        self::assertSame(
            ['Sort47 E', 'Sort47 D', 'Sort47 C', 'Sort47 B', 'Sort47 A'],
            $this->namesOf($players, $this->allPages('name:desc'))
        );
    }

    /**
     * A full game of tied players: from six ties on, MySQL cuts a LIMIT 2 page with an
     * unstable sort, so without the player-id tie-break pages repeat and skip players.
     */
    public function testPlayersWithEqualSortValuesComeBackInPlayerIdOrder(): void
    {
        $players = $this->playFinishedGame(array_fill(0, Game::MAX_PARTICIPANTS, ['Sort47 Twin', '7']));
        $ids = array_keys($players);

        foreach (['name:asc', 'name:desc', 'average:asc', 'average:desc', 'gamesplayed:asc', 'gamesplayed:desc'] as $sort) {
            self::assertSame($ids, array_values(array_intersect($this->allPages($sort), $ids)), $sort);
        }
    }

    /**
     * Plays one finished game of one round in which each player throws the same dart three times.
     *
     * @param list<array{string, string}> $seats the display name and the dart of each player, in seat order
     *
     * @return array<int, string> the display name by player id
     */
    private function playFinishedGame(array $seats): array
    {
        $game = (new Game())
            ->setDate(new DateTime())
            ->setStatus(GameStatus::Started)
            ->setRound(1)
            ->setStartScore(101)
            ->setOutRule(\App\Enum\OutRule::Single);
        $this->entityManager->persist($game);
        $players = [];
        $position = 0;
        foreach ($seats as [$displayName]) {
            $player = TestUserFactory::create($this->entityManager, 'statssort', 'player', ['ROLE_PLAYER']);
            $player->setDisplayName($displayName);
            $seat = (new GamePlayers())->setPlayer($player)->setPosition(++$position)->setScore(101);
            $game->addGamePlayer($seat);
            $this->entityManager->persist($seat);
            $players[] = $player;
        }
        $this->entityManager->flush();

        $names = [];
        foreach ($players as $seat => $player) {
            for ($dart = 0; $dart < 3; ++$dart) {
                $this->throwDart($game, $player, $seats[$seat][1]);
            }
            $names[(int) $player->getId()] = (string) $player->getDisplayName();
        }
        $this->client->loginUser($this->admin);
        $this->client->request(Request::METHOD_POST, sprintf('/api/game/%d/finish', (int) $game->getGameId()));
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        return $names;
    }

    /**
     * @param string $dart the base value, prefixed with D for a double or T for a triple
     */
    private function throwDart(Game $game, User $player, string $dart): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request(
            Request::METHOD_POST,
            sprintf('/api/game/%d/throw/delta', (int) $game->getGameId()),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'playerId' => $player->getId(),
                'value' => (int) ltrim($dart, 'DT'),
                'isDouble' => 'D' === $dart[0],
                'isTriple' => 'T' === $dart[0],
            ], JSON_THROW_ON_ERROR)
        );
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
    }

    /**
     * Walks every page of the sort, two rows per page, and asserts that no player repeats.
     *
     * @return list<int> the player ids in the order the pages return them
     */
    private function allPages(string $sort): array
    {
        $ids = [];
        $offset = 0;
        do {
            $this->client->loginUser($this->admin);
            $this->client->request(Request::METHOD_GET, sprintf('/api/players/stats?limit=2&offset=%d&sort=%s', $offset, $sort));
            self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
            $page = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($page);
            foreach ($page['items'] as $item) {
                $ids[] = (int) $item['playerId'];
            }
            $offset += 2;
        } while ($offset < (int) $page['total']);

        self::assertSame($ids, array_values(array_unique($ids)));

        return $ids;
    }

    /**
     * @param array<int, string> $players the display name by player id
     * @param list<int>          $ids
     *
     * @return list<string> the display names of the given players, in the order of $ids
     */
    private function namesOf(array $players, array $ids): array
    {
        return array_values(array_map(
            static fn(int $id): string => $players[$id],
            array_filter($ids, static fn(int $id): bool => isset($players[$id]))
        ));
    }
}
