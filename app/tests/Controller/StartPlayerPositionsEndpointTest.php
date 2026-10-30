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
use Symfony\Component\HttpFoundation\Response;

/**
 * Start seats the players in the order of playerPositions and rejects duplicate or unknown ids (#59).
 */
final class StartPlayerPositionsEndpointTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private User $first;
    private User $second;
    private int $gameId;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $admin = TestUserFactory::create($this->entityManager, 'startorder', 'admin', ['ROLE_ADMIN']);
        $this->first = TestUserFactory::create($this->entityManager, 'startorder', 'first', ['ROLE_PLAYER']);
        $this->second = TestUserFactory::create($this->entityManager, 'startorder', 'second', ['ROLE_PLAYER']);
        $game = (new Game())->setDate(new DateTime())->setStartScore(301)->setStatus(GameStatus::Lobby);
        $this->entityManager->persist($game);
        foreach ([1 => $this->first, 2 => $this->second] as $position => $user) {
            $seat = (new GamePlayers())->setPlayer($user)->setPosition($position)->setScore(0)->setIsWinner(false);
            $game->addGamePlayer($seat);
            $this->entityManager->persist($seat);
        }
        $this->entityManager->flush();
        $this->gameId = (int) $game->getGameId();
        $this->entityManager->clear();
        $this->client->loginUser($admin);
    }

    public function testStartWithADuplicateIdIsRejectedAndKeepsTheLobby(): void
    {
        $before = $this->storedSeats();
        $secondId = (int) $this->second->getId();

        $this->start([$secondId, $secondId]);

        $this->assertInvalidPlayerOrder(sprintf('Duplicate playerId in positions payload: %d.', $secondId));
        self::assertSame($before, $this->storedSeats());
    }

    public function testStartWithIdsOutsideTheGameIsRejectedAndKeepsTheLobby(): void
    {
        $before = $this->storedSeats();
        $outsider = TestUserFactory::create($this->entityManager, 'startorder', 'outsider', ['ROLE_PLAYER']);
        $this->entityManager->flush();
        $outsiderId = (int) $outsider->getId();

        $this->start([(int) $this->first->getId(), $outsiderId]);

        $this->assertInvalidPlayerOrder(sprintf('Player %d is not part of the game.', $outsiderId));
        self::assertSame($before, $this->storedSeats());
    }

    public function testStartWithAPermutationSeatsThePlayersInThatOrder(): void
    {
        $this->start([(int) $this->second->getId(), (int) $this->first->getId()]);

        self::assertResponseStatusCodeSame(Response::HTTP_OK, (string) $this->client->getResponse()->getContent());
        self::assertSame('started', $this->storedStatus());
        self::assertSame(
            [(int) $this->first->getId() => 2, (int) $this->second->getId() => 1],
            array_map(static fn (array $seat): int => (int) $seat['position'], $this->storedSeats()),
        );
    }

    /**
     * @param list<int> $playerPositions
     */
    private function start(array $playerPositions): void
    {
        $payload = json_encode(['playerPositions' => $playerPositions], JSON_THROW_ON_ERROR);
        $this->client->request(Request::METHOD_POST, sprintf('/api/game/%d/start', $this->gameId), [], [], ['CONTENT_TYPE' => 'application/json'], $payload);
        $this->entityManager->clear();
    }

    private function assertInvalidPlayerOrder(string $message): void
    {
        $content = (string) $this->client->getResponse()->getContent();
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST, $content);
        self::assertSame(
            ['success' => false, 'error' => 'GAME_INVALID_PLAYER_ORDER', 'message' => $message],
            json_decode($content, true, 512, JSON_THROW_ON_ERROR),
        );
        self::assertSame('lobby', $this->storedStatus());
    }

    private function storedStatus(): string
    {
        return (string) $this->entityManager->getConnection()->fetchOne('SELECT status FROM game WHERE game_id = ?', [$this->gameId]);
    }

    /**
     * Each seat's position and score, keyed by player id.
     *
     * @return array<int, array{position: mixed, score: mixed}>
     */
    private function storedSeats(): array
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT player_id, position, score FROM game_players WHERE game_id = ? ORDER BY player_id',
            [$this->gameId],
        );
        $seats = [];
        foreach ($rows as $row) {
            $seats[(int) $row['player_id']] = ['position' => $row['position'], 'score' => $row['score']];
        }

        return $seats;
    }
}
