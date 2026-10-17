<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\Version;
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

    private Connection $server;
    private Connection $connection;
    private string $databaseName;

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var Connection $testConnection */
        $testConnection = static::getContainer()->get('doctrine.dbal.default_connection');
        $params = $testConnection->getParams();
        $serverParams = array_intersect_key($params, array_flip(['driver', 'host', 'port', 'user', 'password', 'charset', 'serverVersion']));

        $this->databaseName = sprintf('%s_one_lobby_migration', (string) ($params['dbname'] ?? 'testphp_db'));
        $this->server = DriverManager::getConnection($serverParams);
        $this->server->executeStatement(sprintf('DROP DATABASE IF EXISTS `%s`', $this->databaseName));
        $this->server->executeStatement(sprintf('CREATE DATABASE `%s`', $this->databaseName));
        $this->connection = DriverManager::getConnection([...$serverParams, 'dbname' => $this->databaseName]);
    }

    protected function tearDown(): void
    {
        $this->connection->close();
        $this->server->executeStatement(sprintf('DROP DATABASE IF EXISTS `%s`', $this->databaseName));
        $this->server->close();
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
        $projectDir = static::getContainer()->getParameter('kernel.project_dir');
        self::assertIsString($projectDir);

        $dependencyFactory = DependencyFactory::fromConnection(
            new ConfigurationArray([
                'migrations_paths' => ['DoctrineMigrations' => $projectDir.'/migrations'],
            ]),
            new ExistingConnection($this->connection),
        );
        $dependencyFactory->getMetadataStorage()->ensureInitialized();
        $plan = $dependencyFactory->getMigrationPlanCalculator()->getPlanUntilVersion(new Version($version));
        $dependencyFactory->getMigrator()->migrate($plan, new MigratorConfiguration());
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
