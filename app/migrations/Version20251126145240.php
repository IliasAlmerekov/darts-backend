<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251126145240 extends AbstractMigration
{
    private function isPostgreSql(): bool
    {
        return $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform;
    }

    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        if (true === $this->isPostgreSql()) {
            $this->addSql("ALTER TABLE game ADD finished_at TIME(0) WITHOUT TIME ZONE DEFAULT NULL");
            $this->addSql("COMMENT ON COLUMN game.finished_at IS '(DC2Type:time_immutable)'");
            $this->addSql('ALTER TABLE game_players ADD is_winner BOOLEAN DEFAULT NULL');

            return;
        }

        $this->addSql('ALTER TABLE game ADD finished_at TIME DEFAULT NULL COMMENT \'(DC2Type:time_immutable)\'');
        $this->addSql('ALTER TABLE game_players ADD is_winner TINYINT(1) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE game_players DROP is_winner');
        $this->addSql('ALTER TABLE game DROP finished_at');
    }
}
