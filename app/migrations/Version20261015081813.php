<?php

/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261015081813 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the rememberme_token table for the Doctrine remember-me token provider';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE rememberme_token (series VARCHAR(88) NOT NULL, value VARCHAR(88) NOT NULL, lastUsed DATETIME NOT NULL, class VARCHAR(100) DEFAULT \'\' NOT NULL, username VARCHAR(200) NOT NULL, PRIMARY KEY (series))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE rememberme_token');
    }
}
