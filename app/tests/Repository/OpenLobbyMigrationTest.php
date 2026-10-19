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
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Runs the one-lobby migration on a scratch database that holds several lobbies.
 *
 * The migration runs DDL, which commits implicitly in MySQL, so it cannot run inside the
 * DAMA test transaction. The test uses its own connection to its own database instead.
 */
final class OpenLobbyMigrationTest extends KernelTestCase
{
    private const string PREVIOUS_VERSION = 'DoctrineMigrations\Version20261015081813';
    private const string ONE_LOBBY_VERSION = 'DoctrineMigrations\Version20261017120000';

    private ScratchDatabase $database;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var Connection $testConnection */
        $testConnection = static::getContainer()->get('doctrine.dbal.default_connection');
        $projectDir = static::getContainer()->getParameter('kernel.project_dir');
        self::assertIsString($projectDir);
        $this->database = new ScratchDatabase($testConnection, 'one_lobby_migration', $projectDir);
        $this->connection = $this->database->connect();
    }

    protected function tearDown(): void
    {
        $this->database->drop();
        parent::tearDown();
    }

    public function testMigrationAbortsEveryLobbyAndThenAllowsOnlyOne(): void
    {
        $this->migrateTo(self::PREVIOUS_VERSION);
        $firstLobby = $this->insertGame('lobby');
        $secondLobby = $this->insertGame('lobby');
        $thirdLobby = $this->insertGame('lobby');
        $started = $this->insertGame('started');
        $finished = $this->insertGame('finished');

        $this->migrateTo(self::ONE_LOBBY_VERSION);

        self::assertSame('aborted', $this->statusOf($firstLobby));
        self::assertSame('aborted', $this->statusOf($secondLobby));
        self::assertSame('aborted', $this->statusOf($thirdLobby));
        self::assertSame('started', $this->statusOf($started));
        self::assertSame('finished', $this->statusOf($finished));

        $this->insertGame('lobby');
        $this->expectException(UniqueConstraintViolationException::class);
        $this->insertGame('lobby');
    }

    private function migrateTo(string $version): void
    {
        $this->database->migrate($version);
    }

    private function insertGame(string $status): int
    {
        $this->connection->insert('game', ['date' => '2026-10-01', 'status' => $status]);

        return (int) $this->connection->lastInsertId();
    }

    private function statusOf(int $gameId): string
    {
        $status = $this->connection->fetchOne('SELECT status FROM game WHERE game_id = ?', [$gameId]);
        self::assertIsString($status);

        return $status;
    }
}
