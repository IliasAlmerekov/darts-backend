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
 * Runs the migration that drops the unused `game.type` column (#50) on a scratch database.
 * The migration runs DDL, so it cannot run inside the DAMA test transaction.
 */
final class GameTypeMigrationTest extends KernelTestCase
{
    private const string PREVIOUS_VERSION = 'DoctrineMigrations\Version20261029095353';
    private const string DROP_TYPE_VERSION = 'DoctrineMigrations\Version20261029102401';

    private ScratchDatabase $database;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var Connection $testConnection */
        $testConnection = static::getContainer()->get('doctrine.dbal.default_connection');
        $projectDir = static::getContainer()->getParameter('kernel.project_dir');
        self::assertIsString($projectDir);
        $this->database = new ScratchDatabase($testConnection, 'game_type_migration', $projectDir);
        $this->connection = $this->database->connect();
    }

    protected function tearDown(): void
    {
        $this->database->drop();
        parent::tearDown();
    }

    public function testUpDropsTheColumnAndKeepsTheGames(): void
    {
        $this->database->migrate(self::PREVIOUS_VERSION);
        $this->connection->insert('game', ['date' => '2026-10-01', 'status' => 'finished', 'type' => 2]);
        $gameId = (int) $this->connection->lastInsertId();

        $this->database->migrate(self::DROP_TYPE_VERSION);

        self::assertArrayNotHasKey('type', $this->gameColumns());
        self::assertArrayHasKey('status', $this->gameColumns());
        self::assertSame('finished', $this->connection->fetchOne('SELECT status FROM game WHERE game_id = ?', [$gameId]));
    }

    public function testDownRestoresANullableIntegerColumn(): void
    {
        $this->database->migrate(self::DROP_TYPE_VERSION);

        $this->database->migrate(self::PREVIOUS_VERSION);

        self::assertSame(['DATA_TYPE' => 'int', 'IS_NULLABLE' => 'YES'], $this->gameColumns()['type'] ?? null);
    }

    /**
     * @return array<string, array{DATA_TYPE: string, IS_NULLABLE: string}> column name => type and nullability
     */
    private function gameColumns(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'game'",
        );
        $columns = [];
        foreach ($rows as $row) {
            $columns[(string) $row['COLUMN_NAME']] = ['DATA_TYPE' => (string) $row['DATA_TYPE'], 'IS_NULLABLE' => (string) $row['IS_NULLABLE']];
        }

        return $columns;
    }
}
