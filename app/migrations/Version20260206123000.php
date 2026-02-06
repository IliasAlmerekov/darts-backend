<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260206123000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make invitation.game_id unique and clean up duplicate invitations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DELETE i1 FROM invitation i1 INNER JOIN invitation i2 ON i1.game_id = i2.game_id AND i1.id > i2.id');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_INVITATION_GAME_ID ON invitation (game_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_INVITATION_GAME_ID ON invitation');
    }
}
