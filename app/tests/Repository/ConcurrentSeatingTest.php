<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Repository\GamePlayersRepository;
use App\Repository\InvitationRepository;
use App\Service\Player\PlayerManagementService;
use App\Tests\Support\ScratchDatabase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Seats and invitations committed by another request after this request's snapshot must still count.
 *
 * Two real connections to a scratch database: "request" runs the code under test inside a
 * transaction whose REPEATABLE READ snapshot is already taken, "other" commits a row in
 * between. A plain read in "request" would miss that row and insert a second one.
 */
#[AllowMockObjectsWithoutExpectations]
final class ConcurrentSeatingTest extends KernelTestCase
{
    private ScratchDatabase $database;
    private Connection $request;
    private Connection $other;
    private EntityManagerInterface $entityManager;
    private GamePlayersRepository $gamePlayersRepository;

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var Connection $testConnection */
        $testConnection = static::getContainer()->get('doctrine.dbal.default_connection');
        $projectDir = static::getContainer()->getParameter('kernel.project_dir');
        self::assertIsString($projectDir);
        $this->database = new ScratchDatabase($testConnection, 'concurrent_seating', $projectDir);
        $this->database->migrate();
        $this->request = $this->database->connect();
        $this->other = $this->database->connect();

        /** @var EntityManagerInterface $testEntityManager */
        $testEntityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager = new EntityManager($this->request, $testEntityManager->getConfiguration());
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->entityManager);
        $this->gamePlayersRepository = new GamePlayersRepository($registry);
    }

    protected function tearDown(): void
    {
        $this->entityManager->close();
        $this->database->drop();
        parent::tearDown();
    }

    public function testAppendMissingPlayersCountsSeatsCommittedAfterTheSnapshot(): void
    {
        $bob = $this->insertUser('bob');
        $vanya = $this->insertUser('vanya');
        $finished = $this->insertGame('finished');
        $lobby = $this->insertGame('lobby');
        $this->insertSeat($finished, $bob, 1);
        $this->insertSeat($finished, $vanya, 2);
        $service = new PlayerManagementService($this->gamePlayersRepository, $this->entityManager);

        $this->request->beginTransaction();
        $this->takeSnapshot();
        // Another rematch into the same lobby seats Bob and commits.
        $this->insertSeat($lobby, $bob, 1);
        $service->appendMissingPlayers($finished, $lobby);
        $this->request->commit();

        self::assertSame(
            [[$bob, 1], [$vanya, 2]],
            $this->other->fetchAllNumeric('SELECT player_id, position FROM game_players WHERE game_id = ? ORDER BY position, game_player_id', [$lobby]),
        );
    }

    public function testIsPlayerInGameForShareSeesASeatCommittedAfterTheSnapshot(): void
    {
        $anna = $this->insertUser('anna');
        $lobby = $this->insertGame('lobby');

        $this->request->beginTransaction();
        $this->takeSnapshot();
        // A rematch seats Anna and commits while her join waits for the lobby lock.
        $this->insertSeat($lobby, $anna, 1);
        $seated = $this->gamePlayersRepository->isPlayerInGameForShare($lobby, $anna);
        $this->request->commit();

        self::assertTrue($seated);
    }

    public function testFindOneByGameIdForShareSeesAnInvitationCommittedAfterTheSnapshot(): void
    {
        $lobby = $this->insertGame('lobby');
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->entityManager);
        $invitationRepository = new InvitationRepository($registry);

        $this->request->beginTransaction();
        $this->takeSnapshot();
        // Another rematch into the same lobby creates its invitation and commits.
        $this->other->insert('invitation', ['uuid' => '0192f0c4-7d3e-7a6b-9c1d-2e3f4a5b6c7d', 'game_id' => $lobby]);
        $invitation = $invitationRepository->findOneByGameIdForShare($lobby);
        $this->request->commit();

        self::assertSame($lobby, $invitation?->getGameId());
    }

    /**
     * The first consistent read fixes the transaction's REPEATABLE READ snapshot.
     */
    private function takeSnapshot(): void
    {
        $this->request->fetchOne('SELECT COUNT(*) FROM game_players');
    }

    private function insertUser(string $name): int
    {
        $this->other->insert('user', [
            'email' => $name.'@test.dev',
            'roles' => '["ROLE_PLAYER"]',
            'password' => 'unused',
            'username' => $name,
            'display_name' => $name,
        ]);

        return (int) $this->other->lastInsertId();
    }

    private function insertGame(string $status): int
    {
        $this->other->insert('game', ['date' => '2026-10-01', 'status' => $status]);

        return (int) $this->other->lastInsertId();
    }

    private function insertSeat(int $gameId, int $playerId, int $position): void
    {
        $this->other->insert('game_players', [
            'game_id' => $gameId,
            'player_id' => $playerId,
            'position' => $position,
            'display_name_snapshot' => 'player',
        ]);
    }
}
