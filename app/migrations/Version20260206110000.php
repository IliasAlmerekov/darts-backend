<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260206110000 extends AbstractMigration
{
    private function isPostgreSql(): bool
    {
        return $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform;
    }

    private function quoteIdentifier(string $identifier): string
    {
        return $this->connection->quoteIdentifier($identifier);
    }

    public function getDescription(): string
    {
        return 'Convert game.finished_at from TIME_IMMUTABLE to DATETIME_IMMUTABLE';
    }

    public function up(Schema $schema): void
    {
        if (true === $this->isPostgreSql()) {
            $this->addSql('ALTER TABLE game ADD finished_at_new TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
            $this->addSql("COMMENT ON COLUMN game.finished_at_new IS '(DC2Type:datetime_immutable)'");
            $this->addSql(sprintf(
                'UPDATE game SET finished_at_new = CASE WHEN finished_at IS NULL THEN NULL ELSE (CAST(%s AS TIMESTAMP(0)) + finished_at) END',
                $this->quoteIdentifier('date'),
            ));
            $this->addSql('ALTER TABLE game DROP COLUMN finished_at');
            $this->addSql('ALTER TABLE game RENAME COLUMN finished_at_new TO finished_at');

            return;
        }

        $this->addSql("ALTER TABLE game ADD finished_at_new DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
        $this->addSql("UPDATE game SET finished_at_new = CASE WHEN finished_at IS NULL THEN NULL ELSE TIMESTAMP(`date`, finished_at) END");
        $this->addSql('ALTER TABLE game DROP finished_at');
        $this->addSql("ALTER TABLE game CHANGE finished_at_new finished_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        if (true === $this->isPostgreSql()) {
            $this->addSql('ALTER TABLE game ADD finished_at_old TIME(0) WITHOUT TIME ZONE DEFAULT NULL');
            $this->addSql("COMMENT ON COLUMN game.finished_at_old IS '(DC2Type:time_immutable)'");
            $this->addSql('UPDATE game SET finished_at_old = CASE WHEN finished_at IS NULL THEN NULL ELSE CAST(finished_at AS TIME(0)) END');
            $this->addSql('ALTER TABLE game DROP COLUMN finished_at');
            $this->addSql('ALTER TABLE game RENAME COLUMN finished_at_old TO finished_at');

            return;
        }

        $this->addSql("ALTER TABLE game ADD finished_at_old TIME DEFAULT NULL COMMENT '(DC2Type:time_immutable)'");
        $this->addSql('UPDATE game SET finished_at_old = CASE WHEN finished_at IS NULL THEN NULL ELSE TIME(finished_at) END');
        $this->addSql('ALTER TABLE game DROP finished_at');
        $this->addSql("ALTER TABLE game CHANGE finished_at_old finished_at TIME DEFAULT NULL COMMENT '(DC2Type:time_immutable)'");
    }
}
