<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Tests\Support\ScratchDatabase;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Runs the Registration date migration on a scratch database that holds players, a guest,
 * and the admin. The migration runs DDL, so it cannot run inside the DAMA test transaction.
 */
final class RegistrationDateMigrationTest extends KernelTestCase
{
    private const string PREVIOUS_VERSION = 'DoctrineMigrations\Version20261021123705';
    private const string REGISTRATION_DATE_VERSION = 'DoctrineMigrations\Version20261021142857';

    private ScratchDatabase $database;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var Connection $testConnection */
        $testConnection = static::getContainer()->get('doctrine.dbal.default_connection');
        $projectDir = static::getContainer()->getParameter('kernel.project_dir');
        self::assertIsString($projectDir);
        $this->database = new ScratchDatabase($testConnection, 'registration_date_migration', $projectDir);
        $this->connection = $this->database->connect();
    }

    protected function tearDown(): void
    {
        $this->database->drop();
        parent::tearDown();
    }

    public function testExistingPlayersCountFromTheirEarliestGame(): void
    {
        $this->database->migrate(self::PREVIOUS_VERSION);
        $player = $this->insertUser('player', ['ROLE_PLAYER']);
        $withoutSeat = $this->insertUser('seatless', ['ROLE_PLAYER']);
        $guest = $this->insertUser('guest', ['ROLE_PLAYER'], isGuest: true);
        $admin = $this->insertUser('admin', ['ROLE_ADMIN']);
        $playingAdmin = $this->insertUser('playing-admin', ['ROLE_ADMIN', 'ROLE_PLAYER']);
        foreach (['2026-05-20', '2026-02-14', '2026-09-01'] as $date) {
            $game = $this->insertGame($date);
            $this->seat($game, $player);
            $this->seat($game, $guest);
            $this->seat($game, $admin);
            $this->seat($game, $playingAdmin);
        }

        $this->database->migrate(self::REGISTRATION_DATE_VERSION);

        self::assertSame('2026-02-14 00:00:00', $this->registeredAtOf($player));
        self::assertNull($this->registeredAtOf($withoutSeat));
        self::assertNull($this->registeredAtOf($guest));
        self::assertNull($this->registeredAtOf($admin));
        self::assertNull($this->registeredAtOf($playingAdmin));
    }

    public function testDownDropsTheColumn(): void
    {
        $this->database->migrate(self::REGISTRATION_DATE_VERSION);

        $this->database->migrate(self::PREVIOUS_VERSION);

        $columns = $this->connection->createSchemaManager()->introspectTableColumnsByUnquotedName('user');
        self::assertArrayNotHasKey('registered_at', $columns);
    }

    /**
     * @param list<string> $roles
     */
    private function insertUser(string $label, array $roles, bool $isGuest = false): int
    {
        $this->connection->insert('`user`', [
            'email' => $label.'@db-n.com',
            'username' => $label,
            'display_name' => $label,
            'roles' => json_encode($roles, JSON_THROW_ON_ERROR),
            'password' => 'unused',
            'is_guest' => (int) $isGuest,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    private function insertGame(string $date): int
    {
        $this->connection->insert('game', ['date' => $date, 'status' => 'finished']);

        return (int) $this->connection->lastInsertId();
    }

    private function seat(int $gameId, int $userId): void
    {
        $this->connection->insert('game_players', [
            'game_id' => $gameId,
            'player_id' => $userId,
            'position' => $userId,
            'score' => 0,
            'display_name_snapshot' => 'seat',
        ]);
    }

    private function registeredAtOf(int $userId): ?string
    {
        $registeredAt = $this->connection->fetchOne('SELECT registered_at FROM `user` WHERE id = ?', [$userId]);
        self::assertTrue(null === $registeredAt || is_string($registeredAt));

        return $registeredAt;
    }
}
