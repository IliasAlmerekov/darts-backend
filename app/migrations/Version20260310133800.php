<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260310133800 extends AbstractMigration
{
    private const GAME_TABLE = 'game';
    private const ROUND_TABLE = 'round';
    private const USER_TABLE = 'user';

    private const GAME_STATUS_GAME_ID_INDEX = 'idx_game_status_game_id';
    private const ROUND_FINISHED_AT_ROUND_ID_INDEX = 'idx_round_finished_at_round_id';
    private const USER_IS_GUEST_ID_INDEX = 'idx_user_is_guest_id';

    private function isPostgreSql(): bool
    {
        return $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform;
    }

    private function quoteIdentifier(string $identifier): string
    {
        return $this->connection->quoteIdentifier($identifier);
    }

    private function dropIndexSql(string $tableName, string $indexName): string
    {
        if (true === $this->isPostgreSql()) {
            return sprintf('DROP INDEX %s', $this->quoteIdentifier($indexName));
        }

        return sprintf(
            'DROP INDEX %s ON %s',
            $this->quoteIdentifier($indexName),
            $this->quoteIdentifier($tableName),
        );
    }

    public function getDescription(): string
    {
        return 'Add indexes for player statistics aggregation queries';
    }

    public function up(Schema $schema): void
    {
        $gameTable = $schema->getTable(self::GAME_TABLE);
        if (false === $gameTable->hasIndex(self::GAME_STATUS_GAME_ID_INDEX)) {
            $this->addSql(sprintf(
                'CREATE INDEX %s ON %s (status, game_id)',
                $this->quoteIdentifier(self::GAME_STATUS_GAME_ID_INDEX),
                $this->quoteIdentifier(self::GAME_TABLE),
            ));
        }

        $roundTable = $schema->getTable(self::ROUND_TABLE);
        if (false === $roundTable->hasIndex(self::ROUND_FINISHED_AT_ROUND_ID_INDEX)) {
            $this->addSql(sprintf(
                'CREATE INDEX %s ON %s (finished_at, round_id)',
                $this->quoteIdentifier(self::ROUND_FINISHED_AT_ROUND_ID_INDEX),
                $this->quoteIdentifier(self::ROUND_TABLE),
            ));
        }

        $userTable = $schema->getTable(self::USER_TABLE);
        if (false === $userTable->hasIndex(self::USER_IS_GUEST_ID_INDEX)) {
            $this->addSql(sprintf(
                'CREATE INDEX %s ON %s (is_guest, id)',
                $this->quoteIdentifier(self::USER_IS_GUEST_ID_INDEX),
                $this->quoteIdentifier(self::USER_TABLE),
            ));
        }
    }

    public function down(Schema $schema): void
    {
        $gameTable = $schema->getTable(self::GAME_TABLE);
        if (true === $gameTable->hasIndex(self::GAME_STATUS_GAME_ID_INDEX)) {
            $this->addSql($this->dropIndexSql(self::GAME_TABLE, self::GAME_STATUS_GAME_ID_INDEX));
        }

        $roundTable = $schema->getTable(self::ROUND_TABLE);
        if (true === $roundTable->hasIndex(self::ROUND_FINISHED_AT_ROUND_ID_INDEX)) {
            $this->addSql($this->dropIndexSql(self::ROUND_TABLE, self::ROUND_FINISHED_AT_ROUND_ID_INDEX));
        }

        $userTable = $schema->getTable(self::USER_TABLE);
        if (true === $userTable->hasIndex(self::USER_IS_GUEST_ID_INDEX)) {
            $this->addSql($this->dropIndexSql(self::USER_TABLE, self::USER_IS_GUEST_ID_INDEX));
        }
    }
}
