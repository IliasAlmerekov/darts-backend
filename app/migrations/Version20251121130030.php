<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251121130030 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $schemaManager = $this->connection->createSchemaManager();

        $gameTable = $schemaManager->introspectTable('game');
        if (!$gameTable->hasColumn('start_score')) {
            $this->addSql('ALTER TABLE game ADD start_score INT DEFAULT 301 NOT NULL');
        }
        $this->addSql('ALTER TABLE game MODIFY start_score INT DEFAULT 301 NOT NULL');

        if (!$gameTable->hasColumn('double_out')) {
            $this->addSql('ALTER TABLE game ADD double_out TINYINT(1) DEFAULT 0 NOT NULL');
        }
        $this->addSql('ALTER TABLE game MODIFY double_out TINYINT(1) DEFAULT 0 NOT NULL');

        if (!$gameTable->hasColumn('triple_out')) {
            $this->addSql('ALTER TABLE game ADD triple_out TINYINT(1) DEFAULT 0 NOT NULL');
        }
        $this->addSql('ALTER TABLE game MODIFY triple_out TINYINT(1) DEFAULT 0 NOT NULL');

        if ($gameTable->hasColumn('status')) {
            $this->addSql("ALTER TABLE game ADD status_new VARCHAR(255) DEFAULT NULL");

// Migrate data
            $this->addSql("UPDATE game SET status_new = CASE
    WHEN status = 1 THEN 'active'
    WHEN status = 0 THEN 'lobby'
    ELSE 'lobby'
END");

// Drop old and rename new
            $this->addSql("ALTER TABLE game DROP COLUMN status");
            $this->addSql("ALTER TABLE game CHANGE status_new status VARCHAR(255) DEFAULT 'lobby' NOT NULL");
        }

        $gamePlayersTable = $schemaManager->introspectTable('game_players');
        if ($gamePlayersTable->hasIndex('FK_A640D85999E6F5DF')) {
            $this->addSql('DROP INDEX FK_A640D85999E6F5DF ON game_players');
        }

        $roundTable = $schemaManager->introspectTable('round');
        if (!$roundTable->hasColumn('started_at')) {
            $this->addSql('ALTER TABLE round ADD started_at DATETIME DEFAULT NULL');
        }
        if (!$roundTable->hasColumn('finished_at')) {
            $this->addSql('ALTER TABLE round ADD finished_at DATETIME DEFAULT NULL');
        }

        $roundThrowsTable = $schemaManager->introspectTable('round_throws');
        if ($roundThrowsTable->hasIndex('FK_48EA89BF99E6F5DF')) {
            $this->addSql('DROP INDEX FK_48EA89BF99E6F5DF ON round_throws');
        }
        if ($roundThrowsTable->hasIndex('FK_48EA89BFE48FD905')) {
            $this->addSql('DROP INDEX FK_48EA89BFE48FD905 ON round_throws');
        }
        if (!$roundThrowsTable->hasColumn('timestamp')) {
            $this->addSql('ALTER TABLE round_throws ADD timestamp DATETIME NOT NULL');
        }
        $this->addSql('ALTER TABLE round_throws MODIFY timestamp DATETIME NOT NULL, MODIFY is_bust TINYINT(1) DEFAULT 0 NOT NULL, MODIFY is_double TINYINT(1) DEFAULT 0 NOT NULL, MODIFY is_triple TINYINT(1) DEFAULT 0 NOT NULL');

        $userTable = $schemaManager->introspectTable('user');
        if (!$userTable->hasColumn('username')) {
            $this->addSql('ALTER TABLE user ADD username VARCHAR(30) DEFAULT NULL');
        }

        $this->addSql("UPDATE user SET username = CONCAT('user_', id) WHERE username = '' OR username IS NULL");
        $this->addSql('ALTER TABLE user MODIFY username VARCHAR(30) NOT NULL');

        if ($userTable->hasIndex('UNIQ_IDENTIFIER_USERNAME')) {
            $this->addSql('DROP INDEX UNIQ_IDENTIFIER_USERNAME ON user');
        }
        $this->addSql('CREATE UNIQUE INDEX UNIQ_IDENTIFIER_USERNAME ON user (username)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE game DROP start_score, DROP double_out, DROP triple_out, CHANGE status status TINYINT(1) DEFAULT NULL');
        $this->addSql('DROP INDEX UNIQ_IDENTIFIER_USERNAME ON user');
        $this->addSql('ALTER TABLE round_throws DROP timestamp, CHANGE is_bust is_bust TINYINT(1) NOT NULL, CHANGE is_double is_double TINYINT(1) NOT NULL, CHANGE is_triple is_triple TINYINT(1) DEFAULT NULL');
        $this->addSql('CREATE INDEX FK_48EA89BF99E6F5DF ON round_throws (player_id)');
        $this->addSql('CREATE INDEX FK_48EA89BFE48FD905 ON round_throws (game_id)');
        $this->addSql('ALTER TABLE round DROP started_at, DROP finished_at');
        $this->addSql('CREATE INDEX FK_A640D85999E6F5DF ON game_players (player_id)');
    }
}
