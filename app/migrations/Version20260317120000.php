<?php

/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260317120000 extends AbstractMigration
{
    private const ROUND_THROWS_TABLE = 'round_throws';
    private const UNIQUE_CONSTRAINT_NAME = 'uq_round_player_throw';

    private function isPostgreSql(): bool
    {
        return $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform;
    }

    private function quoteIdentifier(string $identifier): string
    {
        return $this->connection->quoteIdentifier($identifier);
    }

    private function dropIndexSql(string $tableName, string $indexName): string
    {
        if (true === $this->isPostgreSql()) {
            return sprintf('DROP INDEX %s', $this->quoteIdentifier($indexName));
        }

        return sprintf(
            'DROP INDEX %s ON %s',
            $this->quoteIdentifier($indexName),
            $this->quoteIdentifier($tableName),
        );
    }

    public function getDescription(): string
    {
        return 'Add unique constraint on round_throws(round_id, player_id, throw_number) to prevent duplicate throws';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(sprintf(
            'CREATE UNIQUE INDEX %s ON %s (round_id, player_id, throw_number)',
            $this->quoteIdentifier(self::UNIQUE_CONSTRAINT_NAME),
            $this->quoteIdentifier(self::ROUND_THROWS_TABLE),
        ));
    }

    public function down(Schema $schema): void
    {
        $this->addSql($this->dropIndexSql(self::ROUND_THROWS_TABLE, self::UNIQUE_CONSTRAINT_NAME));
    }
}
