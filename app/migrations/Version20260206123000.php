<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260206123000 extends AbstractMigration
{
    private const INVITATION_TABLE = 'invitation';
    private const INVITATION_GAME_ID_UNIQUE_INDEX = 'UNIQ_INVITATION_GAME_ID';

    private function isPostgreSql(): bool
    {
        return $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform;
    }

    private function dropIndexSql(string $tableName, string $indexName): string
    {
        if (true === $this->isPostgreSql()) {
            return sprintf('DROP INDEX %s', $this->connection->quoteIdentifier($indexName));
        }

        return sprintf(
            'DROP INDEX %s ON %s',
            $this->connection->quoteIdentifier($indexName),
            $this->connection->quoteIdentifier($tableName),
        );
    }

    public function getDescription(): string
    {
        return 'Make invitation.game_id unique and clean up duplicate invitations';
    }

    public function up(Schema $schema): void
    {
        if (true === $this->isPostgreSql()) {
            $this->addSql('DELETE FROM invitation i1 USING invitation i2 WHERE i1.game_id = i2.game_id AND i1.id > i2.id');
        } else {
            $this->addSql('DELETE i1 FROM invitation i1 INNER JOIN invitation i2 ON i1.game_id = i2.game_id AND i1.id > i2.id');
        }

        $invitationTable = $schema->getTable(self::INVITATION_TABLE);
        if (false === $invitationTable->hasIndex(self::INVITATION_GAME_ID_UNIQUE_INDEX)) {
            $this->addSql(sprintf(
                'CREATE UNIQUE INDEX %s ON %s (game_id)',
                $this->connection->quoteIdentifier(self::INVITATION_GAME_ID_UNIQUE_INDEX),
                $this->connection->quoteIdentifier(self::INVITATION_TABLE),
            ));
        }
    }

    public function down(Schema $schema): void
    {
        $invitationTable = $schema->getTable(self::INVITATION_TABLE);
        if (true === $invitationTable->hasIndex(self::INVITATION_GAME_ID_UNIQUE_INDEX)) {
            $this->addSql($this->dropIndexSql(self::INVITATION_TABLE, self::INVITATION_GAME_ID_UNIQUE_INDEX));
        }
    }
}
