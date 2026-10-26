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

final class Version20261026160250 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add game_players.placement and copy the stored final places from position (#29)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_players ADD placement INT DEFAULT NULL');
        // Until now position also held the final place. The turn order of old games is lost, so
        // position stays as it is. A seat of a game that is still running has a place only when
        // it has checked out (score 0).
        $this->addSql("UPDATE game_players gp INNER JOIN game g ON g.game_id = gp.game_id SET gp.placement = gp.position WHERE g.status = 'finished' OR gp.score = 0");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_players DROP placement');
    }
}
