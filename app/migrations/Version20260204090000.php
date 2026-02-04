<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260204090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add is_guest flag to users';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD is_guest BOOLEAN DEFAULT FALSE NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user DROP is_guest');
    }
}
