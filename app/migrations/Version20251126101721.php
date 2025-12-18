<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251126101721 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE game ADD winner_id INT DEFAULT NULL, ADD invitation_id INT DEFAULT NULL, DROP winner, CHANGE start_score start_score INT DEFAULT 301');
        $this->addSql('ALTER TABLE game ADD CONSTRAINT FK_232B318C5DFCD4B8 FOREIGN KEY (winner_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE game ADD CONSTRAINT FK_232B318CA35D7AF0 FOREIGN KEY (invitation_id) REFERENCES invitation (id)');
        $this->addSql('CREATE INDEX IDX_232B318C5DFCD4B8 ON game (winner_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_232B318CA35D7AF0 ON game (invitation_id)');
        $this->addSql('ALTER TABLE game_players ADD CONSTRAINT FK_B38C3C89E48FD905 FOREIGN KEY (game_id) REFERENCES game (game_id)');
        $this->addSql('ALTER TABLE game_players ADD CONSTRAINT FK_B38C3C8999E6F5DF FOREIGN KEY (player_id) REFERENCES user (id)');
        $this->addSql('CREATE INDEX IDX_B38C3C89E48FD905 ON game_players (game_id)');
        $this->addSql('CREATE INDEX IDX_B38C3C8999E6F5DF ON game_players (player_id)');
        $this->addSql('ALTER TABLE round ADD CONSTRAINT FK_C5EEEA34E48FD905 FOREIGN KEY (game_id) REFERENCES game (game_id)');
        $this->addSql('CREATE INDEX IDX_C5EEEA34E48FD905 ON round (game_id)');
        $this->addSql('ALTER TABLE round_throws ADD CONSTRAINT FK_674BC0E9E48FD905 FOREIGN KEY (game_id) REFERENCES game (game_id)');
        $this->addSql('ALTER TABLE round_throws ADD CONSTRAINT FK_674BC0E9A6005CA0 FOREIGN KEY (round_id) REFERENCES round (round_id)');
        $this->addSql('ALTER TABLE round_throws ADD CONSTRAINT FK_674BC0E999E6F5DF FOREIGN KEY (player_id) REFERENCES user (id)');
        $this->addSql('CREATE INDEX IDX_674BC0E9E48FD905 ON round_throws (game_id)');
        $this->addSql('CREATE INDEX IDX_674BC0E9A6005CA0 ON round_throws (round_id)');
        $this->addSql('CREATE INDEX IDX_674BC0E999E6F5DF ON round_throws (player_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE round DROP FOREIGN KEY FK_C5EEEA34E48FD905');
        $this->addSql('DROP INDEX IDX_C5EEEA34E48FD905 ON round');
        $this->addSql('ALTER TABLE round_throws DROP FOREIGN KEY FK_674BC0E9E48FD905');
        $this->addSql('ALTER TABLE round_throws DROP FOREIGN KEY FK_674BC0E9A6005CA0');
        $this->addSql('ALTER TABLE round_throws DROP FOREIGN KEY FK_674BC0E999E6F5DF');
        $this->addSql('DROP INDEX IDX_674BC0E9E48FD905 ON round_throws');
        $this->addSql('DROP INDEX IDX_674BC0E9A6005CA0 ON round_throws');
        $this->addSql('DROP INDEX IDX_674BC0E999E6F5DF ON round_throws');
        $this->addSql('ALTER TABLE game_players DROP FOREIGN KEY FK_B38C3C89E48FD905');
        $this->addSql('ALTER TABLE game_players DROP FOREIGN KEY FK_B38C3C8999E6F5DF');
        $this->addSql('DROP INDEX IDX_B38C3C89E48FD905 ON game_players');
        $this->addSql('DROP INDEX IDX_B38C3C8999E6F5DF ON game_players');
        $this->addSql('ALTER TABLE game DROP FOREIGN KEY FK_232B318C5DFCD4B8');
        $this->addSql('ALTER TABLE game DROP FOREIGN KEY FK_232B318CA35D7AF0');
        $this->addSql('DROP INDEX IDX_232B318C5DFCD4B8 ON game');
        $this->addSql('DROP INDEX UNIQ_232B318CA35D7AF0 ON game');
        $this->addSql('ALTER TABLE game ADD winner VARCHAR(255) DEFAULT NULL, DROP winner_id, DROP invitation_id, CHANGE start_score start_score INT DEFAULT 301 NOT NULL');
    }
}
