<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260206110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Convert game.finished_at from TIME_IMMUTABLE to DATETIME_IMMUTABLE';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE game ADD finished_at_new DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
        $this->addSql("UPDATE game SET finished_at_new = CASE WHEN finished_at IS NULL THEN NULL ELSE TIMESTAMP(`date`, finished_at) END");
        $this->addSql('ALTER TABLE game DROP finished_at');
        $this->addSql("ALTER TABLE game CHANGE finished_at_new finished_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE game ADD finished_at_old TIME DEFAULT NULL COMMENT '(DC2Type:time_immutable)'");
        $this->addSql('UPDATE game SET finished_at_old = CASE WHEN finished_at IS NULL THEN NULL ELSE TIME(finished_at) END');
        $this->addSql('ALTER TABLE game DROP finished_at');
        $this->addSql("ALTER TABLE game CHANGE finished_at_old finished_at TIME DEFAULT NULL COMMENT '(DC2Type:time_immutable)'");
    }
}
