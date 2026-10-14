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

final class Version20261014100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop idx_game_players_name_lower and idx_round_throws_game_throw: DBAL 4 cannot read the functional index, and neither index is used';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX `idx_game_players_name_lower` ON `game_players`');
        $this->addSql('DROP INDEX `idx_round_throws_game_throw` ON `round_throws`');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE INDEX `idx_game_players_name_lower` ON `game_players` (game_id, (LOWER(TRIM(display_name_snapshot))))');
        $this->addSql('CREATE INDEX `idx_round_throws_game_throw` ON `round_throws` (game_id, throw_id)');
    }
}
