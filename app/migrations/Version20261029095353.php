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

final class Version20261029095353 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop the unused player_stats table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE player_stats');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE player_stats (player_id INT AUTO_INCREMENT NOT NULL, wins INT DEFAULT NULL, win_percentage INT NOT NULL, round_average INT DEFAULT NULL, highest_checkout INT DEFAULT NULL, games_played INT DEFAULT NULL, PRIMARY KEY(player_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }
}
