<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260213103000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add display_name for users and display_name_snapshot for game players';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD display_name VARCHAR(30) DEFAULT NULL');
        $this->addSql("UPDATE user SET display_name = username WHERE display_name IS NULL OR display_name = ''");
        $this->addSql('ALTER TABLE user MODIFY display_name VARCHAR(30) NOT NULL');

        $this->addSql('ALTER TABLE game_players ADD display_name_snapshot VARCHAR(30) DEFAULT NULL');
        $this->addSql("UPDATE game_players gp INNER JOIN user u ON u.id = gp.player_id SET gp.display_name_snapshot = u.username WHERE gp.display_name_snapshot IS NULL OR gp.display_name_snapshot = ''");
        $this->addSql('ALTER TABLE game_players MODIFY display_name_snapshot VARCHAR(30) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_players DROP display_name_snapshot');
        $this->addSql('ALTER TABLE user DROP display_name');
    }
}
