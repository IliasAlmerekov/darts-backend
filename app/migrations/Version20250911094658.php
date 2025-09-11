<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250911094658 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE game (game_id INT AUTO_INCREMENT NOT NULL, type INT DEFAULT NULL, winner VARCHAR(255) DEFAULT NULL, date DATE NOT NULL, status TINYINT(1) DEFAULT NULL, round INT DEFAULT NULL, PRIMARY KEY(game_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE game_players (game_player_id INT AUTO_INCREMENT NOT NULL, game_id INT NOT NULL, player_id INT NOT NULL, position INT NOT NULL, score INT NOT NULL, PRIMARY KEY(game_player_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE player_stats (player_id INT AUTO_INCREMENT NOT NULL, wins INT DEFAULT NULL, win_percentage INT NOT NULL, round_average INT DEFAULT NULL, highest_checkout INT DEFAULT NULL, games_played INT DEFAULT NULL, PRIMARY KEY(player_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE round (round_id INT AUTO_INCREMENT NOT NULL, game_id INT NOT NULL, round_number INT NOT NULL, PRIMARY KEY(round_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE round_throws (throw_id INT AUTO_INCREMENT NOT NULL, game_id INT NOT NULL, round_id INT NOT NULL, player_id INT NOT NULL, throw_number INT NOT NULL, value INT NOT NULL, is_bust TINYINT(1) NOT NULL, is_double TINYINT(1) NOT NULL, is_triple TINYINT(1) DEFAULT NULL, score INT NOT NULL, PRIMARY KEY(throw_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE user (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, UNIQUE INDEX UNIQ_IDENTIFIER_EMAIL (email), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', available_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', delivered_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_75EA56E0FB7336F0 (queue_name), INDEX IDX_75EA56E0E3BD61CE (available_at), INDEX IDX_75EA56E016BA31DB (delivered_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE game');
        $this->addSql('DROP TABLE game_players');
        $this->addSql('DROP TABLE player_stats');
        $this->addSql('DROP TABLE round');
        $this->addSql('DROP TABLE round_throws');
        $this->addSql('DROP TABLE user');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
