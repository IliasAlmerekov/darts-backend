<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260213103000 extends AbstractMigration
{
    private function isPostgreSql(): bool
    {
        return $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform;
    }

    private function userTable(): string
    {
        return true === $this->isPostgreSql() ? $this->connection->quoteIdentifier('user') : 'user';
    }

    public function getDescription(): string
    {
        return 'Add display_name for users and display_name_snapshot for game players';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(sprintf('ALTER TABLE %s ADD display_name VARCHAR(30) DEFAULT NULL', $this->userTable()));
        $this->addSql(sprintf("UPDATE %s SET display_name = username WHERE display_name IS NULL OR display_name = ''", $this->userTable()));
        if (true === $this->isPostgreSql()) {
            $this->addSql(sprintf('ALTER TABLE %s ALTER COLUMN display_name SET NOT NULL', $this->userTable()));
        } else {
            $this->addSql(sprintf('ALTER TABLE %s MODIFY display_name VARCHAR(30) NOT NULL', $this->userTable()));
        }

        $this->addSql('ALTER TABLE game_players ADD display_name_snapshot VARCHAR(30) DEFAULT NULL');
        if (true === $this->isPostgreSql()) {
            $this->addSql(sprintf('UPDATE game_players AS gp SET display_name_snapshot = u.username FROM %s AS u WHERE u.id = gp.player_id AND (gp.display_name_snapshot IS NULL OR gp.display_name_snapshot = \'\')', $this->userTable()));
            $this->addSql('ALTER TABLE game_players ALTER COLUMN display_name_snapshot SET NOT NULL');
        } else {
            $this->addSql("UPDATE game_players gp INNER JOIN user u ON u.id = gp.player_id SET gp.display_name_snapshot = u.username WHERE gp.display_name_snapshot IS NULL OR gp.display_name_snapshot = ''");
            $this->addSql('ALTER TABLE game_players MODIFY display_name_snapshot VARCHAR(30) NOT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_players DROP display_name_snapshot');
        $this->addSql(sprintf('ALTER TABLE %s DROP display_name', $this->userTable()));
    }
}
