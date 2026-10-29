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
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every response that carries a seat's position also carries its placement, and the API docs say so (#29).
 */
final class PlacementApiContractEndpointTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private User $first;
    private User $second;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $admin = TestUserFactory::create($this->entityManager, 'plcontract', 'admin', ['ROLE_ADMIN']);
        $this->first = TestUserFactory::create($this->entityManager, 'plcontract', 'first', ['ROLE_PLAYER']);
        $this->second = TestUserFactory::create($this->entityManager, 'plcontract', 'second', ['ROLE_PLAYER']);
        $this->entityManager->flush();
        $this->client->loginUser($admin);
    }

    public function testGameStateAndScoreboardDeltaCarryPositionAndPlacement(): void
    {
        // The second player has checked out already, so the seats show a placement and a different position.
        $gameId = $this->createGame(GameStatus::Started, [[$this->first, 1, 301, null], [$this->second, 2, 0, 1]]);

        $state = $this->requestJson(Request::METHOD_GET, sprintf('/api/game/%d', $gameId));
        self::assertSame([[1, null], [2, 1]], $this->pairs($state['players'] ?? null));

        $ack = $this->requestJson(Request::METHOD_POST, sprintf('/api/game/%d/throw/delta', $gameId), ['playerId' => (int) $this->first->getId(), 'value' => 20]);
        $delta = $ack['scoreboardDelta'] ?? null;
        self::assertIsArray($delta);
        self::assertSame([[1, null], [2, 1]], $this->pairs($delta['changedPlayers'] ?? null));
    }

    public function testFinishedSummaryCarriesPositionAndPlacement(): void
    {
        // The turn order contradicts the placement.
        $gameId = $this->createGame(GameStatus::Finished, [[$this->first, 1, 40, 2], [$this->second, 2, 0, 1]]);

        $summary = $this->requestJson(Request::METHOD_GET, sprintf('/api/game/%d/finished', $gameId));

        self::assertSame([[1, 2], [2, 1]], $this->pairs($summary['finishedPlayers'] ?? null));
    }

    public function testAddedGuestCarriesPositionAndPlacement(): void
    {
        $gameId = $this->createGame(GameStatus::Lobby, [[$this->first, 1, 301, null]]);

        $body = $this->requestJson(Request::METHOD_POST, sprintf('/api/room/%d/guest', $gameId), ['username' => 'Alex']);

        $player = $body['player'] ?? null;
        self::assertIsArray($player);
        self::assertSame(2, $player['position'] ?? null);
        self::assertArrayHasKey('placement', $player);
        self::assertNull($player['placement']);
    }

    public function testApiDocsListPositionAndPlacementTogether(): void
    {
        $application = new Application(static::bootKernel());
        $tester = new CommandTester($application->find('nelmio:apidoc:dump'));
        $tester->execute(['--format' => 'json']);
        $doc = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($doc);

        foreach (['PlayerResponseDto', 'ScoreboardPlayerDeltaDto', 'GameSummaryFinishedPlayerDto'] as $schema) {
            $properties = $doc['components']['schemas'][$schema]['properties'] ?? [];
            self::assertArrayHasKey('position', $properties, $schema);
            self::assertArrayHasKey('placement', $properties, $schema);
            self::assertTrue($properties['placement']['nullable'] ?? false, $schema);
        }

        $guestPlayer = $doc['paths']['/api/room/{id}/guest']['post']['responses']['200']['content']['application/json']['schema']['properties']['player']['properties'] ?? [];
        self::assertArrayHasKey('position', $guestPlayer);
        self::assertArrayHasKey('placement', $guestPlayer);
    }

    /**
     * @param list<array{0: User, 1: int, 2: int, 3: int|null}> $seats user, position, score, placement
     */
    private function createGame(GameStatus $status, array $seats): int
    {
        $game = (new Game())->setDate(new DateTime())->setStartScore(301)->setOutRule(\App\Enum\OutRule::Single)->setStatus($status)->setRound(1);
        if (GameStatus::Finished === $status) {
            $game->setFinishedAt(new DateTimeImmutable());
        }
        $this->entityManager->persist($game);
        foreach ($seats as [$user, $position, $score, $placement]) {
            $seat = (new GamePlayers())->setPlayer($user)->setPosition($position)->setScore($score)->setPlacement($placement)->setIsWinner(1 === $placement);
            $game->addGamePlayer($seat);
            $this->entityManager->persist($seat);
        }
        $this->entityManager->flush();
        $this->entityManager->clear();

        return (int) $game->getGameId();
    }

    /**
     * The position and placement of each row, ordered by position.
     *
     * @return list<array{0: mixed, 1: mixed}>
     */
    private function pairs(mixed $rows): array
    {
        self::assertIsArray($rows);
        $pairs = [];
        foreach ($rows as $row) {
            self::assertIsArray($row);
            self::assertArrayHasKey('position', $row);
            self::assertArrayHasKey('placement', $row);
            $pairs[(int) $row['position']] = [$row['position'], $row['placement']];
        }
        ksort($pairs);

        return array_values($pairs);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function requestJson(string $method, string $uri, array $payload = []): array
    {
        $this->client->request($method, $uri, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(Response::HTTP_OK, (string) $this->client->getResponse()->getContent());

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
