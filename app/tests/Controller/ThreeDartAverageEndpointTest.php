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
 * Every average the API returns is the three-dart average: points divided by darts thrown,
 * times three. A turn with a bust scores 0 and counts as 3 darts.
 */
final class ThreeDartAverageEndpointTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private User $admin;
    private User $anna;
    private User $boris;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->admin = TestUserFactory::create($this->entityManager, 'threedart', 'admin', ['ROLE_ADMIN']);
        $this->anna = TestUserFactory::create($this->entityManager, 'threedart', 'anna', ['ROLE_PLAYER']);
        $this->boris = TestUserFactory::create($this->entityManager, 'threedart', 'boris', ['ROLE_PLAYER']);
        $this->anna->setDisplayName('Anna');
        $this->boris->setDisplayName('Boris');
        $this->entityManager->flush();
    }

    public function testBustTurnAndCheckoutTurnGiveTheSameAverageInSummaryStatsAndProfile(): void
    {
        $game = $this->createStartedGame();

        $this->throwDarts($game, $this->anna, ['T20', '20', '25']);
        $this->throwDarts($game, $this->boris, ['1', '1', '1']);
        $this->throwDarts($game, $this->anna, ['T17', 'D25']);

        self::assertSame('finished', $this->get(sprintf('/api/game/%d', (int) $game->getGameId()))['status'] ?? null);
        $summary = $this->get(sprintf('/api/game/%d/finished', (int) $game->getGameId()));
        self::assertSame(60.6, $summary['winnerRoundAverage']);
        self::assertSame(2, $summary['winnerRoundsPlayed']);
        self::assertSame(
            [[$this->anna->getId(), 2, 60.6], [$this->boris->getId(), 1, 3.0]],
            array_map(
                static fn(array $player): array => [$player['playerId'], $player['roundsPlayed'], $player['roundAverage']],
                $summary['finishedPlayers']
            )
        );

        $profile = $this->get('/api/player/profile', $this->anna);
        self::assertSame(60.6, $profile['stats']['scoreAverage']);
        self::assertSame(60.6, $profile['recentGames'][0]['roundAverage']);
        self::assertSame(60.6, $this->statsRow($this->anna)['scoreAverage']);
        self::assertSame(3.0, $this->statsRow($this->boris)['scoreAverage']);
    }

    public function testBustOnTheFirstDartOfATurnCountsAsThreeDartsAndZeroPoints(): void
    {
        $game = $this->createStartedGame();

        $this->throwDarts($game, $this->anna, ['20', '20', '10']);
        $this->throwDarts($game, $this->boris, ['1', '1', '1']);
        $this->throwDarts($game, $this->anna, ['T20']);
        $this->post(sprintf('/api/game/%d/finish', (int) $game->getGameId()));

        $summary = $this->get(sprintf('/api/game/%d/finished', (int) $game->getGameId()));
        $averages = [];
        foreach ($summary['finishedPlayers'] as $player) {
            $averages[$player['playerId']] = [$player['roundsPlayed'], $player['roundAverage']];
        }
        // Anna: 50 points over turns of 3 + 3 darts.
        self::assertSame([2, 25.0], $averages[$this->anna->getId()]);
        self::assertSame(25.0, $this->get('/api/player/profile', $this->anna)['stats']['scoreAverage']);
        self::assertSame(25.0, $this->statsRow($this->anna)['scoreAverage']);
    }

    public function testManualEndCountsTheOpenTurnWithTheDartsThrown(): void
    {
        $game = $this->createStartedGame();

        $this->throwDarts($game, $this->anna, ['20', '20', '20']);
        $this->throwDarts($game, $this->boris, ['1', '1', '1']);
        $this->throwDarts($game, $this->anna, ['20']);
        $this->post(sprintf('/api/game/%d/finish', (int) $game->getGameId()));

        $summary = $this->get(sprintf('/api/game/%d/finished', (int) $game->getGameId()));
        $averages = [];
        foreach ($summary['finishedPlayers'] as $player) {
            $averages[$player['playerId']] = [$player['roundsPlayed'], $player['roundAverage']];
        }
        // Anna: 80 points over 4 darts; Boris: 3 points over his one turn.
        self::assertSame([2, 60.0], $averages[$this->anna->getId()]);
        self::assertSame([1, 3.0], $averages[$this->boris->getId()]);
        self::assertSame(60.0, $this->statsRow($this->anna)['scoreAverage']);
    }

    public function testPlayerStatsAreSortedByTheThreeDartAverage(): void
    {
        $game = $this->createStartedGame();
        $this->throwDarts($game, $this->anna, ['T20', '20', '25']);
        $this->throwDarts($game, $this->boris, ['20', '20', 'T7']);
        $this->throwDarts($game, $this->anna, ['T17', 'D25']);

        $this->client->loginUser($this->admin);
        $this->client->request(Request::METHOD_GET, '/api/players/stats?limit=100&sort=average:desc');
        $items = $this->decode()['items'];
        $ids = array_column($items, 'playerId');

        // Boris 61.0 (3 darts, 61 points) leads Anna's 60.6, although a busted turn once gave her 80.
        self::assertLessThan(array_search($this->anna->getId(), $ids, true), array_search($this->boris->getId(), $ids, true));
    }

    private function createStartedGame(): Game
    {
        $game = (new Game())
            ->setDate(new DateTime())
            ->setStatus(GameStatus::Started)
            ->setRound(1)
            ->setStartScore(101)
            ->setDoubleOut(true)
            ->setTripleOut(false);
        $this->entityManager->persist($game);
        foreach ([1 => $this->anna, 2 => $this->boris] as $position => $player) {
            $seat = (new GamePlayers())->setPlayer($player)->setPosition($position)->setScore(101);
            $game->addGamePlayer($seat);
            $this->entityManager->persist($seat);
        }
        $this->entityManager->flush();

        return $game;
    }

    /**
     * @param list<string> $darts the base value, prefixed with D for a double or T for a triple
     */
    private function throwDarts(Game $game, User $player, array $darts): void
    {
        foreach ($darts as $dart) {
            $double = 'D' === $dart[0];
            $triple = 'T' === $dart[0];
            $value = (int) ltrim($dart, 'DT');
            $this->client->loginUser($this->admin);
            $this->client->request(
                Request::METHOD_POST,
                sprintf('/api/game/%d/throw/delta', (int) $game->getGameId()),
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                json_encode(['playerId' => $player->getId(), 'value' => $value, 'isDouble' => $double, 'isTriple' => $triple], JSON_THROW_ON_ERROR)
            );
            self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        }
    }

    private function post(string $uri): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request(Request::METHOD_POST, $uri);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
    }

    /**
     * @return array<array-key, mixed>
     */
    private function get(string $uri, ?User $as = null): array
    {
        $this->client->loginUser($as ?? $this->admin);
        $this->client->request(Request::METHOD_GET, $uri);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        return $this->decode();
    }

    /**
     * @return array<array-key, mixed>
     */
    private function statsRow(User $player): array
    {
        $rows = array_values(array_filter(
            $this->get('/api/players/stats?limit=100')['items'],
            static fn(array $row): bool => $row['playerId'] === $player->getId()
        ));
        self::assertCount(1, $rows);

        return $rows[0];
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decode(): array
    {
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
    }
}
