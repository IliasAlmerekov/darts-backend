<?php

declare(strict_types=1);

namespace DoctrineMigrations;

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
                self::GAME_STATUS_GAME_ID_INDEX,
                self::GAME_TABLE,
            ));
        }

        $roundTable = $schema->getTable(self::ROUND_TABLE);
        if (false === $roundTable->hasIndex(self::ROUND_FINISHED_AT_ROUND_ID_INDEX)) {
            $this->addSql(sprintf(
                'CREATE INDEX %s ON `%s` (finished_at, round_id)',
                self::ROUND_FINISHED_AT_ROUND_ID_INDEX,
                self::ROUND_TABLE,
            ));
        }

        $userTable = $schema->getTable(self::USER_TABLE);
        if (false === $userTable->hasIndex(self::USER_IS_GUEST_ID_INDEX)) {
            $this->addSql(sprintf(
                'CREATE INDEX %s ON `%s` (is_guest, id)',
                self::USER_IS_GUEST_ID_INDEX,
                self::USER_TABLE,
            ));
        }
    }

    public function down(Schema $schema): void
    {
        $gameTable = $schema->getTable(self::GAME_TABLE);
        if (true === $gameTable->hasIndex(self::GAME_STATUS_GAME_ID_INDEX)) {
            $this->addSql(sprintf(
                'DROP INDEX %s ON %s',
                self::GAME_STATUS_GAME_ID_INDEX,
                self::GAME_TABLE,
            ));
        }

        $roundTable = $schema->getTable(self::ROUND_TABLE);
        if (true === $roundTable->hasIndex(self::ROUND_FINISHED_AT_ROUND_ID_INDEX)) {
            $this->addSql(sprintf(
                'DROP INDEX %s ON `%s`',
                self::ROUND_FINISHED_AT_ROUND_ID_INDEX,
                self::ROUND_TABLE,
            ));
        }

        $userTable = $schema->getTable(self::USER_TABLE);
        if (true === $userTable->hasIndex(self::USER_IS_GUEST_ID_INDEX)) {
            $this->addSql(sprintf(
                'DROP INDEX %s ON `%s`',
                self::USER_IS_GUEST_ID_INDEX,
                self::USER_TABLE,
            ));
        }
    }
}
