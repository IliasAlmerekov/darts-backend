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

final class Version20260317140000 extends AbstractMigration
{
    private const GAME_PLAYERS_TABLE = 'game_players';
    private const INDEX_NAME = 'idx_game_players_name_lower';

    private function isPostgreSql(): bool
    {
        return $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform;
    }

    private function quoteIdentifier(string $identifier): string
    {
        return $this->connection->quoteIdentifier($identifier);
    }

    public function getDescription(): string
    {
        return 'Add functional index on game_players(game_id, LOWER(TRIM(display_name_snapshot))) to speed up existsNameInGame lookup';
    }

    public function up(Schema $schema): void
    {
        $table = $this->quoteIdentifier(self::GAME_PLAYERS_TABLE);
        $index = $this->quoteIdentifier(self::INDEX_NAME);

        if ($this->isPostgreSql()) {
            $this->addSql(sprintf(
                'CREATE INDEX %s ON %s (game_id, LOWER(TRIM(display_name_snapshot)))',
                $index,
                $table,
            ));
        } else {
            // MySQL 8.0+ functional index syntax requires extra parentheses around the expression
            $this->addSql(sprintf(
                'CREATE INDEX %s ON %s (game_id, (LOWER(TRIM(display_name_snapshot))))',
                $index,
                $table,
            ));
        }
    }

    public function down(Schema $schema): void
    {
        $index = $this->quoteIdentifier(self::INDEX_NAME);
        $table = $this->quoteIdentifier(self::GAME_PLAYERS_TABLE);

        if ($this->isPostgreSql()) {
            $this->addSql(sprintf('DROP INDEX %s', $index));
        } else {
            $this->addSql(sprintf('DROP INDEX %s ON %s', $index, $table));
        }
    }
}
