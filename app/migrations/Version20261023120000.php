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

final class Version20261023120000 extends AbstractMigration
{
    /**
     * Each group of rounds with the same game and round number, with the id of the first round.
     * A derived table, so MySQL materializes it before the update or delete scans `round`.
     */
    private const string DUPLICATE_ROUNDS = 'SELECT game_id, round_number, MIN(round_id) AS first_id FROM `round` GROUP BY game_id, round_number HAVING COUNT(*) > 1';

    public function getDescription(): string
    {
        return 'Merge duplicate rounds into the first, link invitations to their game, drop game.invitation_id, and make start_score NOT NULL';
    }

    public function up(Schema $schema): void
    {
        $collision = $this->connection->fetchAssociative('SELECT r.game_id, r.round_number FROM round_throws t INNER JOIN `round` r ON r.round_id = t.round_id GROUP BY r.game_id, r.round_number, t.player_id, t.throw_number HAVING COUNT(*) > 1 LIMIT 1');
        $this->abortIf(
            false !== $collision,
            sprintf(
                'Cannot merge the duplicate rounds of game %s round %s: two of them hold the same player and throw number. Merge them by hand, then run the migration again.',
                (string) ($collision['game_id'] ?? ''),
                (string) ($collision['round_number'] ?? ''),
            ),
        );
        $this->addSql('UPDATE round_throws t INNER JOIN `round` later ON later.round_id = t.round_id INNER JOIN ('.self::DUPLICATE_ROUNDS.') duplicate ON duplicate.game_id = later.game_id AND duplicate.round_number = later.round_number AND later.round_id > duplicate.first_id SET t.round_id = duplicate.first_id');
        // The kept round takes the earliest start and the latest finish of its group, so
        // merging never loses that a round was played or completed.
        $this->addSql('UPDATE `round` kept INNER JOIN (SELECT MIN(round_id) AS first_id, MIN(started_at) AS started_at, MAX(finished_at) AS finished_at FROM `round` GROUP BY game_id, round_number HAVING COUNT(*) > 1) duplicate ON duplicate.first_id = kept.round_id SET kept.started_at = duplicate.started_at, kept.finished_at = duplicate.finished_at');
        $this->addSql('DELETE later FROM `round` later INNER JOIN ('.self::DUPLICATE_ROUNDS.') duplicate ON duplicate.game_id = later.game_id AND duplicate.round_number = later.round_number AND later.round_id > duplicate.first_id');
        $this->addSql('CREATE UNIQUE INDEX uniq_round_game_round_number ON `round` (game_id, round_number)');

        $this->addSql('DELETE invitation FROM invitation LEFT JOIN game ON game.game_id = invitation.game_id WHERE game.game_id IS NULL');
        $this->addSql('ALTER TABLE invitation ADD CONSTRAINT FK_F11D61A2E48FD905 FOREIGN KEY (game_id) REFERENCES game (game_id) ON DELETE CASCADE');

        $this->addSql('UPDATE game SET start_score = 301 WHERE start_score IS NULL');
        $this->addSql('ALTER TABLE game DROP FOREIGN KEY FK_232B318CA35D7AF0');
        $this->addSql('DROP INDEX UNIQ_232B318CA35D7AF0 ON game');
        $this->addSql('ALTER TABLE game DROP invitation_id, CHANGE start_score start_score INT DEFAULT 301 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // Lossy: the merged rounds stay merged, the orphan invitations stay deleted, the
        // filled start scores stay 301, and game.invitation_id comes back empty.
        $this->addSql('ALTER TABLE game ADD invitation_id INT DEFAULT NULL, CHANGE start_score start_score INT DEFAULT 301');
        $this->addSql('ALTER TABLE game ADD CONSTRAINT FK_232B318CA35D7AF0 FOREIGN KEY (invitation_id) REFERENCES invitation (id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_232B318CA35D7AF0 ON game (invitation_id)');
        $this->addSql('ALTER TABLE invitation DROP FOREIGN KEY FK_F11D61A2E48FD905');
        $this->addSql('DROP INDEX uniq_round_game_round_number ON `round`');
    }
}
