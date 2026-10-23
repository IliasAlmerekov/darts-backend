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
 * Undoing the throw that completed a round reopens that round instead of leaving the next one behind.
 */
final class UndoRoundBoundaryEndpointTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private User $player;
    private int $gameId;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $admin = TestUserFactory::create($this->entityManager, 'undoround', 'admin', ['ROLE_ADMIN']);
        $this->player = TestUserFactory::create($this->entityManager, 'undoround', 'player', ['ROLE_PLAYER']);
        $game = (new Game())->setDate(new DateTime())->setStatus(GameStatus::Started)->setRound(1);
        $this->entityManager->persist($game);
        $seat = (new GamePlayers())->setPlayer($this->player)->setPosition(1)->setScore(301);
        $game->addGamePlayer($seat);
        $this->entityManager->persist($seat);
        $this->entityManager->flush();
        $this->gameId = (int) $game->getGameId();
        $this->client->loginUser($admin);
    }

    public function testUndoOfTheThrowThatCompletedARoundDeletesTheNextRoundAndReopensIt(): void
    {
        $this->throwDarts(3);
        self::assertSame([1 => true, 2 => false], $this->roundsFinished());

        $this->undo();

        self::assertSame([1 => false], $this->roundsFinished());
    }

    public function testThrowAfterUndoAtARoundBoundaryCreatesOneNextRound(): void
    {
        $this->throwDarts(3);
        $this->undo();
        self::assertSame([1 => false], $this->roundsFinished());

        $this->throwDarts(1);

        self::assertSame([1 => true, 2 => false], $this->roundsFinished());
    }

    public function testUndoInsideARoundKeepsTheRounds(): void
    {
        $this->throwDarts(2);

        $this->undo();

        self::assertSame([1 => false], $this->roundsFinished());
    }

    private function throwDarts(int $count): void
    {
        for ($i = 0; $i < $count; ++$i) {
            $this->request(Request::METHOD_POST, ['playerId' => (int) $this->player->getId(), 'value' => 1]);
        }
    }

    private function undo(): void
    {
        $this->request(Request::METHOD_DELETE);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function request(string $method, array $payload = []): void
    {
        $uri = sprintf('/api/game/%d/throw/delta', $this->gameId);
        $this->client->request($method, $uri, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        $this->entityManager->clear();
    }

    /**
     * Whether each round of the game is finished, keyed by round number; a duplicate number fails.
     *
     * @return array<int, bool>
     */
    private function roundsFinished(): array
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT round_number, finished_at FROM `round` WHERE game_id = ? ORDER BY round_id',
            [$this->gameId],
        );
        $finished = [];
        foreach ($rows as $row) {
            $roundNumber = (int) $row['round_number'];
            self::assertArrayNotHasKey($roundNumber, $finished, sprintf('Round %d exists twice.', $roundNumber));
            $finished[$roundNumber] = null !== $row['finished_at'];
        }

        return $finished;
    }
}
