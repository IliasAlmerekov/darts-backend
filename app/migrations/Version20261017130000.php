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

final class Version20261017130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Delete duplicate seats, keeping the first, then allow one seat per player and game';
    }

    public function up(Schema $schema): void
    {
        // A self-join would miss rows: MySQL deletes while it scans the joined table.
        // The grouped derived table is materialized before the delete starts.
        $this->addSql('DELETE later FROM game_players later INNER JOIN (SELECT game_id, player_id, MIN(game_player_id) AS first_id FROM game_players GROUP BY game_id, player_id HAVING COUNT(*) > 1) duplicate ON duplicate.game_id = later.game_id AND duplicate.player_id = later.player_id AND later.game_player_id > duplicate.first_id');
        $this->addSql('CREATE UNIQUE INDEX uniq_game_players_game_player ON game_players (game_id, player_id)');
    }

    public function down(Schema $schema): void
    {
        // Lossy: the duplicate seats up() deleted stay deleted.
        $this->addSql('DROP INDEX uniq_game_players_game_player ON game_players');
    }
}
