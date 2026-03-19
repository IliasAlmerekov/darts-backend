<?php

/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260319113000 extends AbstractMigration
{
    private const ROUND_THROWS_TABLE = 'round_throws';
    private const INDEX_NAME = 'idx_round_throws_game_throw';

    public function getDescription(): string
    {
        return 'Add composite index on round_throws(game_id, throw_id) to speed up latest throw lookups by game';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(sprintf(
            'CREATE INDEX `%s` ON `%s` (game_id, throw_id)',
            self::INDEX_NAME,
            self::ROUND_THROWS_TABLE,
        ));
    }

    public function down(Schema $schema): void
    {
        $this->addSql(sprintf(
            'DROP INDEX `%s` ON `%s`',
            self::INDEX_NAME,
            self::ROUND_THROWS_TABLE,
        ));
    }
}
