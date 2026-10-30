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
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Exception\IrreversibleMigration;
use DoctrineMigrations\Version20251121130030;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Rolls back the migration that turned `game.status` into a string column.
 *
 * Its old down() changed the column back to TINYINT(1), which MySQL rejects once a game
 * holds a string status, after earlier statements had already run. The rollback has to
 * refuse up front instead. The rollback runs DDL, so it uses a scratch database outside
 * the DAMA test transaction.
 */
final class IrreversibleStatusMigrationTest extends KernelTestCase
{
    private const string PREVIOUS_VERSION = 'DoctrineMigrations\Version20250916122511';
    private const string STATUS_VERSION = 'DoctrineMigrations\Version20251121130030';

    private Connection $testConnection;
    private string $projectDir;

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var Connection $testConnection */
        $testConnection = static::getContainer()->get('doctrine.dbal.default_connection');
        $projectDir = static::getContainer()->getParameter('kernel.project_dir');
        self::assertIsString($projectDir);
        $this->testConnection = $testConnection;
        $this->projectDir = $projectDir;
    }

    public function testDownThrowsBeforeQueueingAnySql(): void
    {
        require_once $this->projectDir.'/migrations/Version20251121130030.php';
        $migration = new Version20251121130030($this->testConnection, new NullLogger());

        try {
            $migration->down(new Schema());
            self::fail('down() returned instead of refusing to run.');
        } catch (IrreversibleMigration $exception) {
            self::assertStringContainsString('game.status', $exception->getMessage());
        }

        self::assertSame([], $migration->getSql());
    }

    public function testRollbackWithStringStatusesFailsAndLeavesGameColumnsUntouched(): void
    {
        $database = new ScratchDatabase($this->testConnection, 'irreversible_status_migration', $this->projectDir);
        $connection = $database->connect();

        try {
            $database->migrate(self::STATUS_VERSION);
            $connection->insert('game', ['date' => '2025-11-21', 'status' => 'active']);
            $columnsBefore = $connection->fetchAllAssociative('SHOW COLUMNS FROM game');

            $thrown = null;
            try {
                $database->migrate(self::PREVIOUS_VERSION);
            } catch (\Throwable $exception) {
                $thrown = $exception;
            }

            self::assertInstanceOf(IrreversibleMigration::class, $thrown);
            self::assertSame($columnsBefore, $connection->fetchAllAssociative('SHOW COLUMNS FROM game'));
            self::assertSame('active', $connection->fetchOne('SELECT status FROM game'));
        } finally {
            $database->drop();
        }
    }
}
