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

final class Version20261021142857 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the Registration date of a player and backfill it from their earliest game';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD registered_at DATETIME DEFAULT NULL');
        // Players registered before this column count from their first game; one without a seat keeps NULL.
        // The admin never gets a Registration date, even with ROLE_PLAYER on top.
        $this->addSql(<<<'SQL'
            UPDATE `user` u
            INNER JOIN (
                SELECT gp.player_id, MIN(g.date) AS first_game_date
                FROM game_players gp
                INNER JOIN game g ON g.game_id = gp.game_id
                GROUP BY gp.player_id
            ) first_game ON first_game.player_id = u.id
            SET u.registered_at = first_game.first_game_date
            WHERE u.is_guest = 0
                AND JSON_CONTAINS(u.roles, '"ROLE_PLAYER"')
                AND NOT JSON_CONTAINS(u.roles, '"ROLE_ADMIN"')
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP registered_at');
    }
}
