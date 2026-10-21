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

final class Version20261021123705 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index finished games by finishedAt for the last finished game lookup';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_game_status_finished_at_game_id ON game (status, finished_at, game_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_game_status_finished_at_game_id ON game');
    }
}
