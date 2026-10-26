<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Tests\Support\ScratchDatabase;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Runs the placement migration on a scratch database that holds old games. The migration runs
 * DDL, so it cannot run inside the DAMA test transaction (#29).
 */
final class PlacementMigrationTest extends KernelTestCase
{
    private const string PREVIOUS_VERSION = 'DoctrineMigrations\Version20261023120000';
    private const string PLACEMENT_VERSION = 'DoctrineMigrations\Version20261026160250';

    private ScratchDatabase $database;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var Connection $testConnection */
        $testConnection = static::getContainer()->get('doctrine.dbal.default_connection');
        $projectDir = static::getContainer()->getParameter('kernel.project_dir');
        self::assertIsString($projectDir);
        $this->database = new ScratchDatabase($testConnection, 'placement_migration', $projectDir);
        $this->connection = $this->database->connect();
    }

    protected function tearDown(): void
    {
        $this->database->drop();
        parent::tearDown();
    }

    public function testOldFinishedGamesKeepTheirStoredPlacesAndRunningGamesOnlyTheCheckedOutSeats(): void
    {
        $this->database->migrate(self::PREVIOUS_VERSION);
        $users = [$this->insertUser('one'), $this->insertUser('two'), $this->insertUser('three')];
        $finished = $this->insertGame('finished');
        $this->seat($finished, $users[0], 1, 0);
        $this->seat($finished, $users[1], 2, 0);
        $this->seat($finished, $users[2], 3, 120);
        $started = $this->insertGame('started');
        $this->seat($started, $users[0], 1, 0);
        $this->seat($started, $users[1], 2, 85);
        $this->seat($started, $users[2], 3, 301);
        $aborted = $this->insertGame('aborted');
        $this->seat($aborted, $users[0], 2, 40);
        $this->seat($aborted, $users[1], 1, 0);

        $this->database->migrate(self::PLACEMENT_VERSION);

        self::assertSame([1, 2, 3], $this->placementsOf($finished));
        self::assertSame([1, null, null], $this->placementsOf($started));
        self::assertSame([null, 1], $this->placementsOf($aborted));
        self::assertSame([1, 2, 3], $this->positionsOf($finished));
        self::assertSame([2, 1], $this->positionsOf($aborted));
    }

    public function testDownDropsTheColumn(): void
    {
        $this->database->migrate(self::PLACEMENT_VERSION);

        $this->database->migrate(self::PREVIOUS_VERSION);

        $columns = $this->connection->createSchemaManager()->introspectTableColumnsByUnquotedName('game_players');
        self::assertArrayNotHasKey('placement', $columns);
    }

    public function testDownCopiesThePlacementBackIntoPositionForSeatsThatHaveOne(): void
    {
        $this->database->migrate(self::PLACEMENT_VERSION);
        $users = [$this->insertUser('one'), $this->insertUser('two'), $this->insertUser('three')];
        $game = $this->insertGame('finished');
        $this->seat($game, $users[0], 1, 90);
        $this->seat($game, $users[1], 2, 0);
        $this->seat($game, $users[2], 3, 120);
        $this->connection->executeStatement('UPDATE game_players SET placement = 3 - position WHERE position < 3 AND game_id = ?', [$game]);

        $this->database->migrate(self::PREVIOUS_VERSION);

        // The old code reads position as the rank, so the seats with a placement get it back there.
        self::assertSame([2, 1, 3], $this->positionsOf($game));
    }

    private function insertUser(string $label): int
    {
        $this->connection->insert('`user`', [
            'email' => $label.'@db-n.com',
            'username' => $label,
            'display_name' => $label,
            'roles' => json_encode(['ROLE_PLAYER'], JSON_THROW_ON_ERROR),
            'password' => 'unused',
            'is_guest' => 0,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    private function insertGame(string $status): int
    {
        $this->connection->insert('game', ['date' => '2026-05-20', 'status' => $status]);

        return (int) $this->connection->lastInsertId();
    }

    private function seat(int $gameId, int $userId, int $position, int $score): void
    {
        $this->connection->insert('game_players', [
            'game_id' => $gameId,
            'player_id' => $userId,
            'position' => $position,
            'score' => $score,
            'display_name_snapshot' => 'seat',
        ]);
    }

    /**
     * @return list<int|null>
     */
    private function placementsOf(int $gameId): array
    {
        return array_map(
            static fn (mixed $value): ?int => null === $value ? null : (int) $value,
            $this->connection->fetchFirstColumn('SELECT placement FROM game_players WHERE game_id = ? ORDER BY game_player_id', [$gameId]),
        );
    }

    /**
     * @return list<int>
     */
    private function positionsOf(int $gameId): array
    {
        return array_map('intval', $this->connection->fetchFirstColumn('SELECT position FROM game_players WHERE game_id = ? ORDER BY game_player_id', [$gameId]));
    }
}
