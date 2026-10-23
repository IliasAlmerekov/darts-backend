<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Dto\StartGameRequest;
use App\Dto\ThrowRequest;
use App\Entity\Game;
use App\Entity\User;
use App\Exception\Game\GameNotNewestException;
use App\Exception\Game\GameStartNotAllowedException;
use App\Exception\Game\GameThrowNotAllowedException;
use App\Repository\GamePlayersRepository;
use App\Repository\GameRepository;
use App\Repository\InvitationRepository;
use App\Repository\RoundRepository;
use App\Repository\RoundThrowsRepository;
use App\Repository\UserRepositoryInterface;
use App\Service\Game\GameRoomService;
use App\Service\Game\GameSetupService;
use App\Service\Game\GameStartService;
use App\Service\Game\GameThrowService;
use App\Service\Game\NewestGameGuard;
use App\Service\Game\OpenLobbyService;
use App\Service\Game\RematchService;
use App\Service\Player\PlayerManagementService;
use App\Service\Security\GameAccessServiceInterface;
use App\Tests\Support\ScratchDatabase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Seats, invitations and started games committed by another request after this request's
 * snapshot must still count.
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
        $this->gamePlayersRepository = new GamePlayersRepository($this->registry());
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
        $invitationRepository = new InvitationRepository($this->registry());

        $this->request->beginTransaction();
        $this->takeSnapshot();
        // Another rematch into the same lobby creates its invitation and commits.
        $this->other->insert('invitation', ['uuid' => '0192f0c4-7d3e-7a6b-9c1d-2e3f4a5b6c7d', 'game_id' => $lobby]);
        $invitation = $invitationRepository->findOneByGameIdForShare($lobby);
        $this->request->commit();

        self::assertSame($lobby, $invitation?->getGameId());
    }

    public function testRefreshWithSeatsForUpdateSeesTheGameAndSeatsCommittedAfterTheSnapshot(): void
    {
        $bob = $this->insertUser('bob');
        $anna = $this->insertUser('anna');
        $lobby = $this->insertGame('lobby');
        $this->insertSeat($lobby, $bob, 1);
        $gameRepository = new GameRepository($this->registry());

        $this->request->beginTransaction();
        $game = $this->entityManager->find(Game::class, $lobby);
        self::assertInstanceOf(Game::class, $game);
        self::assertCount(1, $game->getGamePlayers());
        // A room create changes the settings and a join adds Anna, both committed while this
        // request waits for the lock.
        $this->other->update('game', ['start_score' => 501], ['game_id' => $lobby]);
        $this->insertSeat($lobby, $anna, 2);
        $gameRepository->refreshWithSeatsForUpdate($game);
        $this->request->commit();

        self::assertSame(501, $game->getStartScore());
        self::assertCount(2, $game->getGamePlayers());
    }

    public function testRematchRejectsAGameReopenedAfterTheSnapshot(): void
    {
        $finished = $this->insertGame('finished');
        $gameRepository = new GameRepository($this->registry());
        $playerManagementService = new PlayerManagementService($this->gamePlayersRepository, $this->entityManager);
        $access = $this->createMock(GameAccessServiceInterface::class);
        $access->method('assertPlayerInGameOrAdmin')->willReturn(new User());
        $openLobbyService = new OpenLobbyService($gameRepository, $this->entityManager);
        $service = new RematchService(
            new GameRoomService($gameRepository, $this->gamePlayersRepository, $playerManagementService, $access, $openLobbyService, $this->createMock(UserRepositoryInterface::class)),
            $playerManagementService,
            new InvitationRepository($this->registry()),
            $this->entityManager,
            $this->createMock(UrlGeneratorInterface::class),
            $access,
            $openLobbyService,
            new NewestGameGuard($gameRepository),
            $gameRepository,
        );

        $this->request->beginTransaction();
        $this->takeSnapshot();
        // A reopen of the finished game commits while the rematch waits for the game's lock.
        $this->other->update('game', ['status' => 'started'], ['game_id' => $finished]);
        try {
            $service->createRematch($finished);
            self::fail('The rematch used the status from before the reopen.');
        } catch (GameNotNewestException) {
            $this->request->rollBack();
        }

        // The reopened game keeps playing, and no lobby was opened.
        self::assertSame(
            [[$finished, 'started']],
            $this->other->fetchAllNumeric('SELECT game_id, status FROM game ORDER BY game_id'),
        );
    }

    public function testAbortStartedGamesAbortsAGameStartedAfterTheSnapshot(): void
    {
        $abandoned = $this->insertGame('started');
        $finished = $this->insertGame('finished');
        $lobby = $this->insertGame('lobby');
        $gameRepository = new GameRepository($this->registry());

        $this->request->beginTransaction();
        $this->takeSnapshot();
        // Another request starts a game and commits while this one runs.
        $startedLater = $this->insertGame('started');
        $gameRepository->abortStartedGames($lobby);
        $this->request->commit();

        self::assertSame(
            [[$abandoned, 'aborted'], [$finished, 'finished'], [$lobby, 'lobby'], [$startedLater, 'aborted']],
            $this->other->fetchAllNumeric('SELECT game_id, status FROM game ORDER BY game_id'),
        );
    }

    public function testAbortStartedGamesWaitsForAParallelStartAndAbortsItOnRetry(): void
    {
        $first = $this->insertGame('lobby');
        $second = $this->insertGame('finished');
        $gameRepository = new GameRepository($this->registry());

        // Another request aborts the started games, starts the first game and has not committed.
        $this->other->beginTransaction();
        $this->other->executeStatement("UPDATE game SET status = 'aborted' WHERE status = 'started' AND game_id <> ?", [$first]);
        $this->other->update('game', ['status' => 'started'], ['game_id' => $first]);

        // This request reopens the second game in parallel, so its bulk update has to wait.
        $this->request->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');
        $this->request->beginTransaction();
        try {
            $gameRepository->abortStartedGames($second);
            self::fail('The bulk update did not wait for the parallel start.');
        } catch (LockWaitTimeoutException) {
            $this->request->rollBack();
        }
        $this->other->commit();

        // RetryingTransaction runs the request again, which now aborts the committed game.
        $this->request->beginTransaction();
        $gameRepository->abortStartedGames($second);
        $this->request->update('game', ['status' => 'started'], ['game_id' => $second]);
        $this->request->commit();

        self::assertSame(
            [[$first, 'aborted'], [$second, 'started']],
            $this->other->fetchAllNumeric('SELECT game_id, status FROM game ORDER BY game_id'),
        );
    }

    public function testFindHighestGameIdBlocksANewGameUntilCommit(): void
    {
        $finished = $this->insertGame('finished');
        $gameRepository = new GameRepository($this->registry());

        // The newest-game guard read the highest id and has not committed.
        $this->request->beginTransaction();
        self::assertSame($finished, $gameRepository->findHighestGameId());

        // A "Back to start" in parallel has to wait, or the guard's answer is stale at commit.
        $this->other->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            $this->insertGame('lobby');
            self::fail('A new game was inserted while the guard held the highest id.');
        } catch (LockWaitTimeoutException) {
        }
        $this->request->commit();

        self::assertSame(
            [[$finished, 'finished']],
            $this->other->fetchAllNumeric('SELECT game_id, status FROM game ORDER BY game_id'),
        );
    }

    public function testRecordThrowRejectsAGameWonAfterItWasLoaded(): void
    {
        [$game, , $bob] = $this->startGameWithOneThrow();
        $loaded = $this->entityManager->find(Game::class, $game);
        self::assertInstanceOf(Game::class, $loaded);
        // The winning throw commits after this request loaded the game.
        $this->commitWinningThrow($game, $bob);
        $throw = new ThrowRequest();
        $throw->playerId = $bob;
        $throw->value = 20;

        try {
            $this->throwService()->recordThrow($loaded, $throw);
            self::fail('The throw used the status from before the winning throw.');
        } catch (GameThrowNotAllowedException) {
        }

        self::assertSame(2, (int) $this->other->fetchOne('SELECT COUNT(*) FROM round_throws WHERE game_id = ?', [$game]));
    }

    public function testUndoReopensAGameWonAfterItWasLoaded(): void
    {
        [$game, $firstThrow, $bob] = $this->startGameWithOneThrow();
        $loaded = $this->entityManager->find(Game::class, $game);
        self::assertInstanceOf(Game::class, $loaded);
        self::assertCount(1, $loaded->getGamePlayers());
        // The winning throw commits after this request loaded the game.
        $this->commitWinningThrow($game, $bob);

        $this->throwService()->undoLastThrow($loaded);

        self::assertSame(
            [['started', null, null]],
            $this->other->fetchAllNumeric('SELECT status, winner_id, finished_at FROM game WHERE game_id = ?', [$game]),
        );
        self::assertSame([[20, 0]], $this->other->fetchAllNumeric('SELECT score, is_winner FROM game_players WHERE game_id = ?', [$game]));
        self::assertSame([$firstThrow], $this->other->fetchFirstColumn('SELECT throw_id FROM round_throws WHERE game_id = ?', [$game]));
    }

    public function testUndoRejectsAGameWonAfterItWasLoadedWhenANewerGameExists(): void
    {
        [$game, , $bob] = $this->startGameWithOneThrow();
        $loaded = $this->entityManager->find(Game::class, $game);
        self::assertInstanceOf(Game::class, $loaded);
        self::assertCount(1, $loaded->getGamePlayers());
        // The winning throw and a "Back to start" commit after this request loaded the game.
        $this->commitWinningThrow($game, $bob);
        $lobby = $this->insertGame('lobby');

        try {
            $this->throwService()->undoLastThrow($loaded);
            self::fail('The undo used the status from before the winning throw.');
        } catch (GameNotNewestException) {
        }

        // The won game keeps its winner and its winning throw.
        self::assertSame(
            [[$game, 'finished', $bob], [$lobby, 'lobby', null]],
            $this->other->fetchAllNumeric('SELECT game_id, status, winner_id FROM game ORDER BY game_id'),
        );
        self::assertSame(2, (int) $this->other->fetchOne('SELECT COUNT(*) FROM round_throws WHERE game_id = ?', [$game]));
    }

    public function testStartRejectsAGameStartedAfterItWasLoaded(): void
    {
        $bob = $this->insertUser('bob');
        $anna = $this->insertUser('anna');
        $lobby = $this->insertGame('lobby');
        $this->insertSeat($lobby, $bob, 1);
        $this->insertSeat($lobby, $anna, 2);
        $gameRepository = new GameRepository($this->registry());
        $access = $this->createMock(GameAccessServiceInterface::class);
        $access->method('assertPlayerInGameOrAdmin')->willReturn(new User());
        $service = new GameStartService(
            new GameSetupService(),
            $this->entityManager,
            $access,
            new RoundRepository($this->registry()),
            $gameRepository,
        );

        $this->request->beginTransaction();
        $loaded = $this->entityManager->find(Game::class, $lobby);
        self::assertInstanceOf(Game::class, $loaded);
        // A parallel start of the same lobby commits after this request loaded the game.
        $this->other->update('game', ['status' => 'started', 'round' => 1], ['game_id' => $lobby]);
        $this->other->insert('round', ['game_id' => $lobby, 'round_number' => 1]);
        try {
            $service->start($loaded, new StartGameRequest());
            self::fail('The start used the status from before the parallel start.');
        } catch (GameStartNotAllowedException) {
            // start() threw before it wrote anything, so commit and rollback end the same.
            $this->request->commit();
        }

        // The parallel start keeps its game and its one first round.
        self::assertSame(
            [['started', 1]],
            $this->other->fetchAllNumeric('SELECT status, round FROM game WHERE game_id = ?', [$lobby]),
        );
        self::assertSame(1, (int) $this->other->fetchOne('SELECT COUNT(*) FROM round WHERE game_id = ? AND round_number = 1', [$lobby]));
    }

    public function testAbortStartedGamesKeepsTheExceptedGameStarted(): void
    {
        $other = $this->insertGame('started');
        $kept = $this->insertGame('started');

        (new GameRepository($this->registry()))->abortStartedGames($kept);

        self::assertSame(
            [[$other, 'aborted'], [$kept, 'started']],
            $this->other->fetchAllNumeric('SELECT game_id, status FROM game ORDER BY game_id'),
        );
    }

    /**
     * Repositories built on it run their queries through the "request" connection.
     */
    private function registry(): ManagerRegistry
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->entityManager);

        return $registry;
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

    private function throwService(): GameThrowService
    {
        $gameRepository = new GameRepository($this->registry());
        $access = $this->createMock(GameAccessServiceInterface::class);
        $access->method('assertPlayerInGameOrAdmin')->willReturn(new User());

        return new GameThrowService(
            $this->gamePlayersRepository,
            new RoundRepository($this->registry()),
            new RoundThrowsRepository($this->registry()),
            $this->entityManager,
            $access,
            $gameRepository,
            new NewestGameGuard($gameRepository),
        );
    }

    /**
     * Bob is on 20 after his first throw in round 1.
     *
     * @return array{int, int, int} the game, the first throw and Bob
     */
    private function startGameWithOneThrow(): array
    {
        $bob = $this->insertUser('bob');
        $game = $this->insertGame('started');
        $this->other->update('game', ['round' => 1], ['game_id' => $game]);
        $this->insertSeat($game, $bob, 1);
        $this->other->update('game_players', ['score' => 20], ['game_id' => $game]);
        $this->other->insert('round', ['game_id' => $game, 'round_number' => 1]);

        return [$game, $this->insertThrow($game, $bob, 1, 20), $bob];
    }

    /**
     * Bob checks out from 20 and wins.
     */
    private function commitWinningThrow(int $gameId, int $bob): void
    {
        $this->insertThrow($gameId, $bob, 2, 0);
        $this->other->update('game_players', ['score' => 0, 'is_winner' => 1], ['game_id' => $gameId]);
        $this->other->update(
            'game',
            ['status' => 'finished', 'winner_id' => $bob, 'finished_at' => '2026-10-01 20:00:00'],
            ['game_id' => $gameId],
        );
    }

    private function insertThrow(int $gameId, int $playerId, int $throwNumber, int $remainingScore): int
    {
        $this->other->insert('round_throws', [
            'game_id' => $gameId,
            'round_id' => $this->other->fetchOne('SELECT round_id FROM round WHERE game_id = ?', [$gameId]),
            'player_id' => $playerId,
            'throw_number' => $throwNumber,
            'value' => 20,
            'score' => $remainingScore,
            'timestamp' => '2026-10-01 20:00:00',
        ]);

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
