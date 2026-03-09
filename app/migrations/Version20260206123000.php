<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260206123000 extends AbstractMigration
{
    private const INVITATION_TABLE = 'invitation';
    private const INVITATION_GAME_ID_UNIQUE_INDEX = 'UNIQ_INVITATION_GAME_ID';

    public function getDescription(): string
    {
        return 'Make invitation.game_id unique and clean up duplicate invitations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DELETE i1 FROM invitation i1 INNER JOIN invitation i2 ON i1.game_id = i2.game_id AND i1.id > i2.id');

        $invitationTable = $schema->getTable(self::INVITATION_TABLE);
        if (false === $invitationTable->hasIndex(self::INVITATION_GAME_ID_UNIQUE_INDEX)) {
            $this->addSql(sprintf(
                'CREATE UNIQUE INDEX %s ON %s (game_id)',
                self::INVITATION_GAME_ID_UNIQUE_INDEX,
                self::INVITATION_TABLE,
            ));
        }
    }

    public function down(Schema $schema): void
    {
        $invitationTable = $schema->getTable(self::INVITATION_TABLE);
        if (true === $invitationTable->hasIndex(self::INVITATION_GAME_ID_UNIQUE_INDEX)) {
            $this->addSql(sprintf(
                'DROP INDEX %s ON %s',
                self::INVITATION_GAME_ID_UNIQUE_INDEX,
                self::INVITATION_TABLE,
            ));
        }
    }
}
