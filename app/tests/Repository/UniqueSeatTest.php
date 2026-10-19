<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Game;
use App\Entity\GamePlayers;
use App\Entity\User;
use App\Enum\GameStatus;
use App\Tests\Support\ScratchDatabase;
use App\Tests\Support\TestUserFactory;
use DateTime;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A player has at most one seat per game.
 */
final class UniqueSeatTest extends KernelTestCase
{
    private const string PREVIOUS_VERSION = 'DoctrineMigrations\Version20261017120000';
    private const string UNIQUE_SEAT_VERSION = 'DoctrineMigrations\Version20261017130000';

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testSecondSeatForTheSamePlayerInTheSameGameFailsAtTheDatabase(): void
    {
        $player = TestUserFactory::create($this->entityManager, 'uniqueseat', 'player', ['ROLE_PLAYER']);
        $game = (new Game())->setDate(new DateTime())->setStatus(GameStatus::Started);
        $this->entityManager->persist($game);
        $this->seat($game, $player, 1);
        $this->entityManager->flush();

        $this->seat($game, $player, 2);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->entityManager->flush();
    }

    public function testMigrationKeepsTheFirstOfDuplicateSeatsAndThenAllowsOnlyOne(): void
    {
        /** @var Connection $testConnection */
        $testConnection = static::getContainer()->get('doctrine.dbal.default_connection');
        $projectDir = static::getContainer()->getParameter('kernel.project_dir');
        self::assertIsString($projectDir);
        $database = new ScratchDatabase($testConnection, 'unique_seat_migration', $projectDir);

        try {
            $database->migrate(self::PREVIOUS_VERSION);
            $connection = $database->connect();
            $connection->insert('user', ['email' => 'bob@test.dev', 'roles' => '[]', 'password' => 'x', 'username' => 'bob', 'display_name' => 'bob']);
            $bob = (int) $connection->lastInsertId();
            $connection->insert('user', ['email' => 'anna@test.dev', 'roles' => '[]', 'password' => 'x', 'username' => 'anna', 'display_name' => 'anna']);
            $anna = (int) $connection->lastInsertId();
            $connection->insert('game', ['date' => '2026-10-01', 'status' => 'finished']);
            $game = (int) $connection->lastInsertId();
            $first = $this->insertSeat($connection, $game, $bob, 1);
            $this->insertSeat($connection, $game, $bob, 2);
            $this->insertSeat($connection, $game, $bob, 3);
            $annaSeat = $this->insertSeat($connection, $game, $anna, 4);

            $database->migrate(self::UNIQUE_SEAT_VERSION);

            self::assertSame(
                [$first, $annaSeat],
                array_map(intval(...), $connection->fetchFirstColumn('SELECT game_player_id FROM game_players ORDER BY game_player_id')),
            );
            $this->expectException(UniqueConstraintViolationException::class);
            $this->insertSeat($connection, $game, $anna, 5);
        } finally {
            $database->drop();
        }
    }

    private function seat(Game $game, User $player, int $position): void
    {
        $seat = (new GamePlayers())->setPlayer($player)->setPosition($position)->setDisplayNameSnapshot('player');
        $game->addGamePlayer($seat);
        $this->entityManager->persist($seat);
    }

    private function insertSeat(Connection $connection, int $gameId, int $playerId, int $position): int
    {
        $connection->insert('game_players', ['game_id' => $gameId, 'player_id' => $playerId, 'position' => $position, 'display_name_snapshot' => 'p']);

        return (int) $connection->lastInsertId();
    }
}
