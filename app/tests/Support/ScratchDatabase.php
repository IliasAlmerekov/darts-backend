<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\Version;

/**
 * A throwaway MySQL database next to the test database, outside the DAMA test transaction.
 *
 * DAMA keeps one real transaction open for the whole run, so tests that need DDL or a
 * second connection that commits use a database of their own. Connections opened here
 * commit for real; drop() removes the database.
 */
final class ScratchDatabase
{
    public readonly string $name;

    private Connection $server;

    /** @var array<string, mixed> */
    private array $serverParams;

    /** @var list<Connection> */
    private array $connections = [];

    /**
     * @param Connection $testConnection The test kernel's default connection, for its server parameters
     * @param string     $suffix         Names the database after the calling test
     * @param string     $projectDir     The kernel's project dir, for the migrations
     */
    public function __construct(Connection $testConnection, string $suffix, private readonly string $projectDir)
    {
        $params = $testConnection->getParams();
        $this->serverParams = array_intersect_key($params, array_flip(['driver', 'host', 'port', 'user', 'password', 'charset', 'serverVersion']));
        $this->name = sprintf('%s_%s', (string) ($params['dbname'] ?? 'testphp_db'), $suffix);
        $this->server = DriverManager::getConnection($this->serverParams);
        $this->server->executeStatement(sprintf('DROP DATABASE IF EXISTS `%s`', $this->name));
        $this->server->executeStatement(sprintf('CREATE DATABASE `%s`', $this->name));
    }

    /**
     * Opens a new connection to the scratch database.
     */
    public function connect(): Connection
    {
        $connection = DriverManager::getConnection([...$this->serverParams, 'dbname' => $this->name]);
        $this->connections[] = $connection;

        return $connection;
    }

    /**
     * Runs the migrations up to and including the given version, or all of them. Uses a
     * connection of its own: MySQL commits DDL implicitly, which leaves DBAL's transaction
     * nesting level on the migrating connection out of step with the server.
     */
    public function migrate(?string $version = null): void
    {
        $connection = DriverManager::getConnection([...$this->serverParams, 'dbname' => $this->name]);
        $dependencyFactory = DependencyFactory::fromConnection(
            new ConfigurationArray([
                'migrations_paths' => ['DoctrineMigrations' => $this->projectDir.'/migrations'],
            ]),
            new ExistingConnection($connection),
        );
        $dependencyFactory->getMetadataStorage()->ensureInitialized();
        $calculator = $dependencyFactory->getMigrationPlanCalculator();
        $target = $version ?? (string) $calculator->getMigrations()->getLast()->getVersion();
        $plan = $calculator->getPlanUntilVersion(new Version($target));
        $dependencyFactory->getMigrator()->migrate($plan, new MigratorConfiguration());
        $connection->close();
    }

    /**
     * Closes every connection and drops the database.
     */
    public function drop(): void
    {
        foreach ($this->connections as $connection) {
            $connection->close();
        }
        $this->server->executeStatement(sprintf('DROP DATABASE IF EXISTS `%s`', $this->name));
        $this->server->close();
    }
}
