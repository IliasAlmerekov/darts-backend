<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260204090000 extends AbstractMigration
{
    private function isPostgreSql(): bool
    {
        return $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform;
    }

    private function userTable(): string
    {
        return true === $this->isPostgreSql() ? $this->connection->quoteIdentifier('user') : 'user';
    }

    public function getDescription(): string
    {
        return 'Add is_guest flag to users';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(sprintf('ALTER TABLE %s ADD is_guest BOOLEAN DEFAULT FALSE NOT NULL', $this->userTable()));
    }

    public function down(Schema $schema): void
    {
        $this->addSql(sprintf('ALTER TABLE %s DROP is_guest', $this->userTable()));
    }
}
