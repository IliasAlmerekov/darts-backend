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

final class Version20261029102401 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop the unused game.type column';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game DROP type');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game ADD type INT DEFAULT NULL');
    }
}
