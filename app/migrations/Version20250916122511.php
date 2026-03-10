<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250916122511 extends AbstractMigration
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
            $this->addSql('ALTER TABLE game_players ALTER COLUMN position DROP NOT NULL');
            $this->addSql('ALTER TABLE game_players ALTER COLUMN score DROP NOT NULL');

            return;
        }

        $this->addSql('ALTER TABLE game_players CHANGE position position INT DEFAULT NULL, CHANGE score score INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        if (true === $this->isPostgreSql()) {
            $this->addSql('ALTER TABLE game_players ALTER COLUMN position SET NOT NULL');
            $this->addSql('ALTER TABLE game_players ALTER COLUMN score SET NOT NULL');

            return;
        }

        $this->addSql('ALTER TABLE game_players CHANGE position position INT NOT NULL, CHANGE score score INT NOT NULL');
    }
}
