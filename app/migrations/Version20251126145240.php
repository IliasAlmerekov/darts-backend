<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251126145240 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game ADD finished_at TIME DEFAULT NULL COMMENT \'(DC2Type:time_immutable)\'');
        $this->addSql('ALTER TABLE game_players ADD is_winner TINYINT(1) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_players DROP is_winner');
        $this->addSql('ALTER TABLE game DROP finished_at');
    }
}
