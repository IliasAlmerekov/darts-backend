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
 * Drops the `messenger_messages` table that symfony/doctrine-messenger left behind (#225).
 *
 * The rollback has to rebuild the table as Version20261014100100 left it: one composite
 * index over (queue_name, available_at, delivered_at, id), no DC2Type column comments.
 */
final class MessengerMessagesMigrationTest extends KernelTestCase
{
    private const string PREVIOUS_VERSION = 'DoctrineMigrations\Version20261029102401';
    private const string VERSION = 'DoctrineMigrations\Version20261030120000';

    public function testUpDropsTheTableAndDownRestoresIt(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        /** @var Connection $testConnection */
        $testConnection = $container->get('doctrine.dbal.default_connection');
        $projectDir = $container->getParameter('kernel.project_dir');
        self::assertIsString($projectDir);
        $database = new ScratchDatabase($testConnection, 'messenger_messages_migration', $projectDir);
        $connection = $database->connect();

        try {
            $database->migrate(self::PREVIOUS_VERSION);
            $tableBefore = $this->showCreateTable($connection);

            $database->migrate(self::VERSION);
            self::assertNotContains('messenger_messages', $connection->createSchemaManager()->listTableNames());

            $database->migrate(self::PREVIOUS_VERSION);
            self::assertSame($tableBefore, $this->showCreateTable($connection));
            self::assertSame(
                [['IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750', 'queue_name,available_at,delivered_at,id']],
                $connection->fetchAllNumeric(
                    "SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'messenger_messages' AND INDEX_NAME <> 'PRIMARY' GROUP BY INDEX_NAME",
                ),
            );

            $database->migrate(self::VERSION);
            self::assertNotContains('messenger_messages', $connection->createSchemaManager()->listTableNames());
        } finally {
            $database->drop();
        }
    }

    private function showCreateTable(Connection $connection): string
    {
        $row = $connection->fetchNumeric('SHOW CREATE TABLE messenger_messages');
        self::assertIsArray($row);
        self::assertIsString($row[1]);

        return $row[1];
    }
}
