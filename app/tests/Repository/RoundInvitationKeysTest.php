<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Game;
use App\Entity\Invitation;
use App\Entity\Round;
use App\Enum\GameStatus;
use App\Tests\Support\ScratchDatabase;
use DateTime;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Migrations\Exception\AbortMigration;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * A game has one round per round number, and an invitation belongs to a game that exists.
 */
final class RoundInvitationKeysTest extends KernelTestCase
{
    private const string PREVIOUS_VERSION = 'DoctrineMigrations\Version20261021142857';
    private const string KEYS_VERSION = 'DoctrineMigrations\Version20261023120000';

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testSecondRoundWithTheSameNumberInTheSameGameFailsAtTheDatabase(): void
    {
        $game = $this->createGame();
        $this->entityManager->persist((new Round())->setGame($game)->setRoundNumber(1));
        $this->entityManager->flush();

        $this->entityManager->persist((new Round())->setGame($game)->setRoundNumber(1));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->entityManager->flush();
    }

    public function testInvitationForAMissingGameFailsAtTheDatabase(): void
    {
        $missingGameId = 1 + (int) $this->entityManager->getConnection()->fetchOne('SELECT COALESCE(MAX(game_id), 0) FROM game');

        $this->expectException(ForeignKeyConstraintViolationException::class);
        $this->entityManager->getConnection()->insert('invitation', ['uuid' => Uuid::v4()->toRfc4122(), 'game_id' => $missingGameId]);
    }

    public function testDeletingAGameDeletesItsInvitation(): void
    {
        $game = $this->createGame();
        $invitation = (new Invitation())->setUuid(Uuid::v4())->setGame($game);
        $this->entityManager->persist($invitation);
        $this->entityManager->flush();
        $invitationId = $invitation->getId();
        $this->entityManager->clear();

        $reloaded = $this->entityManager->find(Game::class, $game->getGameId());
        self::assertInstanceOf(Game::class, $reloaded);
        $this->entityManager->remove($reloaded);
        $this->entityManager->flush();

        self::assertFalse($this->entityManager->getConnection()->fetchOne('SELECT id FROM invitation WHERE id = ?', [$invitationId]));
    }

    public function testMigrationMergesDuplicateRoundsDropsOrphanInvitationsAndFillsTheStartScore(): void
    {
        $this->withScratchDatabase('round_keys_migration', function (ScratchDatabase $database): void {
            $database->migrate(self::PREVIOUS_VERSION);
            $connection = $database->connect();
            $bob = $this->insertUser($connection, 'bob');
            $game = $this->insertGame($connection);
            $kept = $this->insertRound($connection, $game, 1);
            $empty = $this->insertRound($connection, $game, 1);
            $withThrows = $this->insertRound($connection, $game, 1);
            $secondRound = $this->insertRound($connection, $game, 2);
            $keptThrow = $this->insertThrow($connection, $game, $kept, $bob, 1);
            $movedThrow = $this->insertThrow($connection, $game, $withThrows, $bob, 2);
            $connection->insert('invitation', ['uuid' => 'valid', 'game_id' => $game]);
            $connection->insert('invitation', ['uuid' => 'orphan', 'game_id' => $game + 1000]);
            $connection->executeStatement('UPDATE game SET start_score = NULL WHERE game_id = ?', [$game]);
            self::assertNotSame($kept, $empty);

            $database->migrate(self::KEYS_VERSION);

            self::assertSame(
                [[$kept, 1], [$secondRound, 2]],
                array_map(
                    static fn (array $row): array => [(int) $row['round_id'], (int) $row['round_number']],
                    $connection->fetchAllAssociative('SELECT round_id, round_number FROM `round` ORDER BY round_id'),
                ),
            );
            self::assertSame(
                [$keptThrow, $movedThrow],
                array_map(intval(...), $connection->fetchFirstColumn('SELECT throw_id FROM round_throws WHERE round_id = ? ORDER BY throw_id', [$kept])),
            );
            self::assertSame(['valid'], $connection->fetchFirstColumn('SELECT uuid FROM invitation'));
            self::assertSame(301, (int) $connection->fetchOne('SELECT start_score FROM game WHERE game_id = ?', [$game]));
            self::assertFalse($connection->fetchOne("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'game' AND column_name = 'invitation_id'"));
            self::assertSame(
                ['NO', '301'],
                array_values((array) $connection->fetchNumeric("SELECT is_nullable, column_default FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'game' AND column_name = 'start_score'")),
            );
        });
    }

    public function testMigrationKeepsTheEarliestStartAndTheLatestFinishOfDuplicateRounds(): void
    {
        $this->withScratchDatabase('round_keys_timestamps', function (ScratchDatabase $database): void {
            $database->migrate(self::PREVIOUS_VERSION);
            $connection = $database->connect();
            $game = $this->insertGame($connection);
            $kept = $this->insertRound($connection, $game, 1, null, null);
            $this->insertRound($connection, $game, 1, '2026-10-01 12:00:00', '2026-10-01 12:05:00');
            $this->insertRound($connection, $game, 1, '2026-10-01 12:10:00', '2026-10-01 12:15:00');

            $database->migrate(self::KEYS_VERSION);

            self::assertSame(
                ['round_id' => $kept, 'started_at' => '2026-10-01 12:00:00', 'finished_at' => '2026-10-01 12:15:00'],
                array_map(
                    static fn (mixed $value): mixed => is_numeric($value) ? (int) $value : $value,
                    (array) $connection->fetchAssociative('SELECT round_id, started_at, finished_at FROM `round` WHERE game_id = ?', [$game]),
                ),
            );
        });
    }

    public function testMigrationFailsWhenThrowsOfDuplicateRoundsCollide(): void
    {
        $this->withScratchDatabase('round_keys_collision', function (ScratchDatabase $database): void {
            $database->migrate(self::PREVIOUS_VERSION);
            $connection = $database->connect();
            $bob = $this->insertUser($connection, 'bob');
            $game = $this->insertGame($connection);
            $this->insertThrow($connection, $game, $this->insertRound($connection, $game, 3), $bob, 1);
            $this->insertThrow($connection, $game, $this->insertRound($connection, $game, 3), $bob, 1);

            $this->expectException(AbortMigration::class);
            $this->expectExceptionMessage(sprintf('game %d round 3', $game));
            $database->migrate(self::KEYS_VERSION);
        });
    }

    private function createGame(): Game
    {
        $game = (new Game())->setDate(new DateTime())->setStatus(GameStatus::Finished);
        $this->entityManager->persist($game);
        $this->entityManager->flush();

        return $game;
    }

    /**
     * @param callable(ScratchDatabase): void $test
     */
    private function withScratchDatabase(string $suffix, callable $test): void
    {
        /** @var Connection $testConnection */
        $testConnection = static::getContainer()->get('doctrine.dbal.default_connection');
        $projectDir = static::getContainer()->getParameter('kernel.project_dir');
        self::assertIsString($projectDir);
        $database = new ScratchDatabase($testConnection, $suffix, $projectDir);

        try {
            $test($database);
        } finally {
            $database->drop();
        }
    }

    private function insertUser(Connection $connection, string $name): int
    {
        $connection->insert('user', ['email' => $name.'@test.dev', 'roles' => '[]', 'password' => 'x', 'username' => $name, 'display_name' => $name]);

        return (int) $connection->lastInsertId();
    }

    private function insertGame(Connection $connection): int
    {
        $connection->insert('game', ['date' => '2026-10-01', 'status' => 'finished']);

        return (int) $connection->lastInsertId();
    }

    private function insertRound(Connection $connection, int $gameId, int $roundNumber, ?string $startedAt = null, ?string $finishedAt = null): int
    {
        $connection->insert('round', ['game_id' => $gameId, 'round_number' => $roundNumber, 'started_at' => $startedAt, 'finished_at' => $finishedAt]);

        return (int) $connection->lastInsertId();
    }

    private function insertThrow(Connection $connection, int $gameId, int $roundId, int $playerId, int $throwNumber): int
    {
        $connection->insert('round_throws', [
            'game_id' => $gameId,
            'round_id' => $roundId,
            'player_id' => $playerId,
            'throw_number' => $throwNumber,
            'value' => 20,
            'score' => 281,
            'timestamp' => '2026-10-01 12:00:00',
        ]);

        return (int) $connection->lastInsertId();
    }
}
