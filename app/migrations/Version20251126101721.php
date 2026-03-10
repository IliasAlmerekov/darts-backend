<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251126101721 extends AbstractMigration
{
    private function isPostgreSql(): bool
    {
        return $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform;
    }

    private function quoteIdentifier(string $identifier): string
    {
        return $this->connection->quoteIdentifier($identifier);
    }

    private function userTable(): string
    {
        return true === $this->isPostgreSql() ? $this->quoteIdentifier('user') : 'user';
    }

    private function dropIndexSql(string $tableName, string $indexName): string
    {
        if (true === $this->isPostgreSql()) {
            return sprintf('DROP INDEX %s', $this->quoteIdentifier($indexName));
        }

        return sprintf(
            'DROP INDEX %s ON %s',
            $this->quoteIdentifier($indexName),
            $this->quoteIdentifier($tableName),
        );
    }

    private function dropForeignKeySql(string $tableName, string $constraintName): string
    {
        if (true === $this->isPostgreSql()) {
            return sprintf(
                'ALTER TABLE %s DROP CONSTRAINT %s',
                $this->quoteIdentifier($tableName),
                $this->quoteIdentifier($constraintName),
            );
        }

        return sprintf(
            'ALTER TABLE %s DROP FOREIGN KEY %s',
            $this->quoteIdentifier($tableName),
            $this->quoteIdentifier($constraintName),
        );
    }

    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        if (true === $this->isPostgreSql()) {
            $this->addSql('ALTER TABLE game ADD winner_id INT DEFAULT NULL');
            $this->addSql('ALTER TABLE game ADD invitation_id INT DEFAULT NULL');
            $this->addSql('ALTER TABLE game DROP COLUMN winner');
            $this->addSql('ALTER TABLE game ALTER COLUMN start_score SET DEFAULT 301');
            $this->addSql('ALTER TABLE game ALTER COLUMN start_score DROP NOT NULL');
        } else {
            $this->addSql('ALTER TABLE game ADD winner_id INT DEFAULT NULL, ADD invitation_id INT DEFAULT NULL, DROP winner, CHANGE start_score start_score INT DEFAULT 301');
        }

        $this->addSql(sprintf('ALTER TABLE game ADD CONSTRAINT %s FOREIGN KEY (winner_id) REFERENCES %s (id)', $this->quoteIdentifier('FK_232B318C5DFCD4B8'), $this->userTable()));
        $this->addSql('ALTER TABLE game ADD CONSTRAINT FK_232B318CA35D7AF0 FOREIGN KEY (invitation_id) REFERENCES invitation (id)');
        $this->addSql('CREATE INDEX IDX_232B318C5DFCD4B8 ON game (winner_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_232B318CA35D7AF0 ON game (invitation_id)');
        $this->addSql('ALTER TABLE game_players ADD CONSTRAINT FK_B38C3C89E48FD905 FOREIGN KEY (game_id) REFERENCES game (game_id)');
        $this->addSql(sprintf('ALTER TABLE game_players ADD CONSTRAINT %s FOREIGN KEY (player_id) REFERENCES %s (id)', $this->quoteIdentifier('FK_B38C3C8999E6F5DF'), $this->userTable()));
        $this->addSql('CREATE INDEX IDX_B38C3C89E48FD905 ON game_players (game_id)');
        $this->addSql('CREATE INDEX IDX_B38C3C8999E6F5DF ON game_players (player_id)');
        $this->addSql('ALTER TABLE round ADD CONSTRAINT FK_C5EEEA34E48FD905 FOREIGN KEY (game_id) REFERENCES game (game_id)');
        $this->addSql('CREATE INDEX IDX_C5EEEA34E48FD905 ON round (game_id)');
        $this->addSql('ALTER TABLE round_throws ADD CONSTRAINT FK_674BC0E9E48FD905 FOREIGN KEY (game_id) REFERENCES game (game_id)');
        $this->addSql('ALTER TABLE round_throws ADD CONSTRAINT FK_674BC0E9A6005CA0 FOREIGN KEY (round_id) REFERENCES round (round_id)');
        $this->addSql(sprintf('ALTER TABLE round_throws ADD CONSTRAINT %s FOREIGN KEY (player_id) REFERENCES %s (id)', $this->quoteIdentifier('FK_674BC0E999E6F5DF'), $this->userTable()));
        $this->addSql('CREATE INDEX IDX_674BC0E9E48FD905 ON round_throws (game_id)');
        $this->addSql('CREATE INDEX IDX_674BC0E9A6005CA0 ON round_throws (round_id)');
        $this->addSql('CREATE INDEX IDX_674BC0E999E6F5DF ON round_throws (player_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql($this->dropForeignKeySql('round', 'FK_C5EEEA34E48FD905'));
        $this->addSql($this->dropIndexSql('round', 'IDX_C5EEEA34E48FD905'));
        $this->addSql($this->dropForeignKeySql('round_throws', 'FK_674BC0E9E48FD905'));
        $this->addSql($this->dropForeignKeySql('round_throws', 'FK_674BC0E9A6005CA0'));
        $this->addSql($this->dropForeignKeySql('round_throws', 'FK_674BC0E999E6F5DF'));
        $this->addSql($this->dropIndexSql('round_throws', 'IDX_674BC0E9E48FD905'));
        $this->addSql($this->dropIndexSql('round_throws', 'IDX_674BC0E9A6005CA0'));
        $this->addSql($this->dropIndexSql('round_throws', 'IDX_674BC0E999E6F5DF'));
        $this->addSql($this->dropForeignKeySql('game_players', 'FK_B38C3C89E48FD905'));
        $this->addSql($this->dropForeignKeySql('game_players', 'FK_B38C3C8999E6F5DF'));
        $this->addSql($this->dropIndexSql('game_players', 'IDX_B38C3C89E48FD905'));
        $this->addSql($this->dropIndexSql('game_players', 'IDX_B38C3C8999E6F5DF'));
        $this->addSql($this->dropForeignKeySql('game', 'FK_232B318C5DFCD4B8'));
        $this->addSql($this->dropForeignKeySql('game', 'FK_232B318CA35D7AF0'));
        $this->addSql($this->dropIndexSql('game', 'IDX_232B318C5DFCD4B8'));
        $this->addSql($this->dropIndexSql('game', 'UNIQ_232B318CA35D7AF0'));
        if (true === $this->isPostgreSql()) {
            $this->addSql('ALTER TABLE game ADD winner VARCHAR(255) DEFAULT NULL');
            $this->addSql('ALTER TABLE game DROP COLUMN winner_id');
            $this->addSql('ALTER TABLE game DROP COLUMN invitation_id');
            $this->addSql('ALTER TABLE game ALTER COLUMN start_score SET DEFAULT 301');
            $this->addSql('ALTER TABLE game ALTER COLUMN start_score SET NOT NULL');

            return;
        }

        $this->addSql('ALTER TABLE game ADD winner VARCHAR(255) DEFAULT NULL, DROP winner_id, DROP invitation_id, CHANGE start_score start_score INT DEFAULT 301 NOT NULL');
    }
}
