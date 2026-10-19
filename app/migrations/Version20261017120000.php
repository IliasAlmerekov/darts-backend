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

final class Version20261017120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Abort every game in the lobby, then allow at most one lobby game with a unique index on a generated column';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE game SET status = 'aborted' WHERE status = 'lobby'");
        $this->addSql("ALTER TABLE game ADD open_lobby TINYINT GENERATED ALWAYS AS (IF(status = 'lobby', 1, NULL)) VIRTUAL");
        $this->addSql('CREATE UNIQUE INDEX uniq_game_open_lobby ON game (open_lobby)');
    }

    public function down(Schema $schema): void
    {
        // Lossy: the games up() moved from lobby to aborted stay aborted.
        $this->addSql('DROP INDEX uniq_game_open_lobby ON game');
        $this->addSql('ALTER TABLE game DROP open_lobby');
    }
}
