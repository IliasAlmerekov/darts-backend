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

final class Version20260317120000 extends AbstractMigration
{
    private const ROUND_THROWS_TABLE = 'round_throws';
    private const UNIQUE_CONSTRAINT_NAME = 'uq_round_player_throw';

    public function getDescription(): string
    {
        return 'Add unique constraint on round_throws(round_id, player_id, throw_number) to prevent duplicate throws';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(sprintf(
            'CREATE UNIQUE INDEX `%s` ON `%s` (round_id, player_id, throw_number)',
            self::UNIQUE_CONSTRAINT_NAME,
            self::ROUND_THROWS_TABLE,
        ));
    }

    public function down(Schema $schema): void
    {
        $this->addSql(sprintf(
            'DROP INDEX `%s` ON `%s`',
            self::UNIQUE_CONSTRAINT_NAME,
            self::ROUND_THROWS_TABLE,
        ));
    }
}
