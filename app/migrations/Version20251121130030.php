<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251121130030 extends AbstractMigration
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

    private function timestampType(): string
    {
        return true === $this->isPostgreSql() ? 'TIMESTAMP(0) WITHOUT TIME ZONE' : 'DATETIME';
    }

    private function boolType(): string
    {
        return true === $this->isPostgreSql() ? 'BOOLEAN' : 'TINYINT(1)';
    }

    private function falseLiteral(): string
    {
        return true === $this->isPostgreSql() ? 'FALSE' : '0';
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

    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $schemaManager = $this->connection->createSchemaManager();

        $gameTable = $schemaManager->introspectTable('game');
        if (false === $gameTable->hasColumn('start_score')) {
            $this->addSql('ALTER TABLE game ADD start_score INT DEFAULT 301 NOT NULL');
        }
        if (true === $this->isPostgreSql()) {
            $this->addSql('ALTER TABLE game ALTER COLUMN start_score SET DEFAULT 301');
            $this->addSql('ALTER TABLE game ALTER COLUMN start_score SET NOT NULL');
        } else {
            $this->addSql('ALTER TABLE game MODIFY start_score INT DEFAULT 301 NOT NULL');
        }

        if (false === $gameTable->hasColumn('double_out')) {
            $this->addSql(sprintf(
                'ALTER TABLE game ADD double_out %s DEFAULT %s NOT NULL',
                $this->boolType(),
                $this->falseLiteral(),
            ));
        }
        if (true === $this->isPostgreSql()) {
            $this->addSql(sprintf('ALTER TABLE game ALTER COLUMN double_out SET DEFAULT %s', $this->falseLiteral()));
            $this->addSql('ALTER TABLE game ALTER COLUMN double_out SET NOT NULL');
        } else {
            $this->addSql('ALTER TABLE game MODIFY double_out TINYINT(1) DEFAULT 0 NOT NULL');
        }

        if (false === $gameTable->hasColumn('triple_out')) {
            $this->addSql(sprintf(
                'ALTER TABLE game ADD triple_out %s DEFAULT %s NOT NULL',
                $this->boolType(),
                $this->falseLiteral(),
            ));
        }
        if (true === $this->isPostgreSql()) {
            $this->addSql(sprintf('ALTER TABLE game ALTER COLUMN triple_out SET DEFAULT %s', $this->falseLiteral()));
            $this->addSql('ALTER TABLE game ALTER COLUMN triple_out SET NOT NULL');
        } else {
            $this->addSql('ALTER TABLE game MODIFY triple_out TINYINT(1) DEFAULT 0 NOT NULL');
        }

        if (true === $gameTable->hasColumn('status')) {
            $this->addSql("ALTER TABLE game ADD status_new VARCHAR(255) DEFAULT NULL");
            $this->addSql("UPDATE game SET status_new = CASE
    WHEN status = 1 THEN 'active'
    WHEN status = 0 THEN 'lobby'
    ELSE 'lobby'
END");
            $this->addSql("ALTER TABLE game DROP COLUMN status");
            if (true === $this->isPostgreSql()) {
                $this->addSql('ALTER TABLE game RENAME COLUMN status_new TO status');
                $this->addSql("ALTER TABLE game ALTER COLUMN status SET DEFAULT 'lobby'");
                $this->addSql('ALTER TABLE game ALTER COLUMN status SET NOT NULL');
            } else {
                $this->addSql("ALTER TABLE game CHANGE status_new status VARCHAR(255) DEFAULT 'lobby' NOT NULL");
            }
        }

        $gamePlayersTable = $schemaManager->introspectTable('game_players');
        if (true === $gamePlayersTable->hasIndex('FK_A640D85999E6F5DF')) {
            $this->addSql($this->dropIndexSql('game_players', 'FK_A640D85999E6F5DF'));
        }

        $roundTable = $schemaManager->introspectTable('round');
        if (false === $roundTable->hasColumn('started_at')) {
            $this->addSql(sprintf('ALTER TABLE round ADD started_at %s DEFAULT NULL', $this->timestampType()));
        }
        if (false === $roundTable->hasColumn('finished_at')) {
            $this->addSql(sprintf('ALTER TABLE round ADD finished_at %s DEFAULT NULL', $this->timestampType()));
        }

        $roundThrowsTable = $schemaManager->introspectTable('round_throws');
        if (true === $roundThrowsTable->hasIndex('FK_48EA89BF99E6F5DF')) {
            $this->addSql($this->dropIndexSql('round_throws', 'FK_48EA89BF99E6F5DF'));
        }
        if (true === $roundThrowsTable->hasIndex('FK_48EA89BFE48FD905')) {
            $this->addSql($this->dropIndexSql('round_throws', 'FK_48EA89BFE48FD905'));
        }
        if (false === $roundThrowsTable->hasColumn('timestamp')) {
            $this->addSql(sprintf(
                'ALTER TABLE round_throws ADD %s %s NOT NULL',
                $this->quoteIdentifier('timestamp'),
                $this->timestampType(),
            ));
        }
        if (true === $this->isPostgreSql()) {
            $this->addSql(sprintf('ALTER TABLE round_throws ALTER COLUMN %s TYPE %s', $this->quoteIdentifier('timestamp'), $this->timestampType()));
            $this->addSql(sprintf('ALTER TABLE round_throws ALTER COLUMN %s SET NOT NULL', $this->quoteIdentifier('timestamp')));
            $this->addSql(sprintf('ALTER TABLE round_throws ALTER COLUMN is_bust SET DEFAULT %s', $this->falseLiteral()));
            $this->addSql('ALTER TABLE round_throws ALTER COLUMN is_bust SET NOT NULL');
            $this->addSql(sprintf('ALTER TABLE round_throws ALTER COLUMN is_double SET DEFAULT %s', $this->falseLiteral()));
            $this->addSql('ALTER TABLE round_throws ALTER COLUMN is_double SET NOT NULL');
            $this->addSql(sprintf('ALTER TABLE round_throws ALTER COLUMN is_triple SET DEFAULT %s', $this->falseLiteral()));
            $this->addSql('ALTER TABLE round_throws ALTER COLUMN is_triple SET NOT NULL');
        } else {
            $this->addSql('ALTER TABLE round_throws MODIFY timestamp DATETIME NOT NULL, MODIFY is_bust TINYINT(1) DEFAULT 0 NOT NULL, MODIFY is_double TINYINT(1) DEFAULT 0 NOT NULL, MODIFY is_triple TINYINT(1) DEFAULT 0 NOT NULL');
        }

        $userTable = $schemaManager->introspectTable('user');
        if (false === $userTable->hasColumn('username')) {
            $this->addSql(sprintf('ALTER TABLE %s ADD username VARCHAR(30) DEFAULT NULL', $this->userTable()));
        }

        $this->addSql(sprintf("UPDATE %s SET username = CONCAT('user_', id) WHERE username = '' OR username IS NULL", $this->userTable()));
        if (true === $this->isPostgreSql()) {
            $this->addSql(sprintf('ALTER TABLE %s ALTER COLUMN username SET NOT NULL', $this->userTable()));
        } else {
            $this->addSql(sprintf('ALTER TABLE %s MODIFY username VARCHAR(30) NOT NULL', $this->userTable()));
        }

        if (true === $userTable->hasIndex('UNIQ_IDENTIFIER_USERNAME')) {
            $this->addSql($this->dropIndexSql('user', 'UNIQ_IDENTIFIER_USERNAME'));
        }
        $this->addSql(sprintf('CREATE UNIQUE INDEX %s ON %s (username)', $this->quoteIdentifier('UNIQ_IDENTIFIER_USERNAME'), $this->userTable()));
    }

    public function down(Schema $schema): void
    {
        if (true === $this->isPostgreSql()) {
            $this->addSql('ALTER TABLE game ADD status_old SMALLINT DEFAULT NULL');
            $this->addSql("UPDATE game SET status_old = CASE WHEN status = 'active' THEN 1 WHEN status = 'lobby' THEN 0 ELSE 0 END");
            $this->addSql('ALTER TABLE game DROP COLUMN status');
            $this->addSql('ALTER TABLE game RENAME COLUMN status_old TO status');
            $this->addSql('ALTER TABLE game DROP COLUMN start_score, DROP COLUMN double_out, DROP COLUMN triple_out');
            $this->addSql($this->dropIndexSql('user', 'UNIQ_IDENTIFIER_USERNAME'));
            $this->addSql(sprintf('ALTER TABLE round_throws DROP COLUMN %s', $this->quoteIdentifier('timestamp')));
            $this->addSql('ALTER TABLE round_throws ALTER COLUMN is_bust DROP DEFAULT');
            $this->addSql('ALTER TABLE round_throws ALTER COLUMN is_double DROP DEFAULT');
            $this->addSql('ALTER TABLE round_throws ALTER COLUMN is_triple DROP DEFAULT');
            $this->addSql('ALTER TABLE round_throws ALTER COLUMN is_triple DROP NOT NULL');
            $this->addSql('CREATE INDEX FK_48EA89BF99E6F5DF ON round_throws (player_id)');
            $this->addSql('CREATE INDEX FK_48EA89BFE48FD905 ON round_throws (game_id)');
            $this->addSql('ALTER TABLE round DROP COLUMN started_at, DROP COLUMN finished_at');
            $this->addSql('CREATE INDEX FK_A640D85999E6F5DF ON game_players (player_id)');

            return;
        }

        $this->addSql('ALTER TABLE game DROP start_score, DROP double_out, DROP triple_out, CHANGE status status TINYINT(1) DEFAULT NULL');
        $this->addSql('DROP INDEX UNIQ_IDENTIFIER_USERNAME ON user');
        $this->addSql('ALTER TABLE round_throws DROP timestamp, CHANGE is_bust is_bust TINYINT(1) NOT NULL, CHANGE is_double is_double TINYINT(1) NOT NULL, CHANGE is_triple is_triple TINYINT(1) DEFAULT NULL');
        $this->addSql('CREATE INDEX FK_48EA89BF99E6F5DF ON round_throws (player_id)');
        $this->addSql('CREATE INDEX FK_48EA89BFE48FD905 ON round_throws (game_id)');
        $this->addSql('ALTER TABLE round DROP started_at, DROP finished_at');
        $this->addSql('CREATE INDEX FK_A640D85999E6F5DF ON game_players (player_id)');
    }
}
