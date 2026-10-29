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

final class OutRuleMigrationTest extends KernelTestCase
{
    public function testFlagsSurviveMigrationAndRollback(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        /** @var Connection $testConnection */
        $testConnection = $container->get('doctrine.dbal.default_connection');
        $projectDir = $container->getParameter('kernel.project_dir');
        self::assertIsString($projectDir);
        $database = new ScratchDatabase($testConnection, 'out_rule_migration', $projectDir);
        $connection = $database->connect();

        try {
            $database->migrate('DoctrineMigrations\Version20261026160250');
            foreach ([[0, 0], [1, 0], [0, 1], [1, 1]] as [$double, $triple]) {
                $connection->insert('game', ['date' => '2026-05-20', 'status' => 'finished', 'double_out' => $double, 'triple_out' => $triple]);
            }
            $database->migrate('DoctrineMigrations\Version20261029085410');
            self::assertSame(['singleout', 'doubleout', 'tripleout', 'doubleout'], $connection->fetchFirstColumn('SELECT out_rule FROM game ORDER BY game_id'));
            $columns = $connection->fetchAllAssociativeIndexed('SHOW COLUMNS FROM game');
            self::assertArrayNotHasKey('double_out', $columns);
            self::assertArrayNotHasKey('triple_out', $columns);
            self::assertSame('NO', $columns['out_rule']['Null']);

            $database->migrate('DoctrineMigrations\Version20261026160250');
            self::assertSame([[0, 0], [1, 0], [0, 1], [1, 0]], array_map(
                static fn(array $row): array => [(int) $row['double_out'], (int) $row['triple_out']],
                $connection->fetchAllAssociative('SELECT double_out, triple_out FROM game ORDER BY game_id'),
            ));
        } finally {
            $database->drop();
        }
    }
}
