<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Game;
use App\Enum\GameStatus;
use App\Tests\Support\ScratchDatabase;
use App\Tests\Support\TestSeatFactory;
use App\Tests\Support\TestUserFactory;
use DateTime;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * A write whose transaction MySQL rolls back after a lock wait timeout answers 409
 * CONCURRENT_CHANGE, so the client can retry (#255).
 *
 * The kernel runs on a scratch database through a connection that commits, because a second
 * connection cannot lock rows that only exist inside the DAMA test transaction.
 */
final class ConcurrentChangeEndpointTest extends WebTestCase
{
    private ScratchDatabase $database;
    private Connection $holder;
    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::bootKernel();
        /** @var Connection $testConnection */
        $testConnection = static::getContainer()->get('doctrine.dbal.default_connection');
        $projectDir = static::getContainer()->getParameter('kernel.project_dir');
        self::assertIsString($projectDir);
        $this->database = new ScratchDatabase($testConnection, 'concurrent_change', $projectDir);
        $this->database->migrate();
        self::ensureKernelShutdown();

        $this->client = static::createClient();
        $request = $this->database->connect();
        $request->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');
        // Holds for one request only: the client reboots the kernel before a second one.
        static::getContainer()->set('doctrine.dbal.default_connection', $request);
        $this->holder = $this->database->connect();
    }

    protected function tearDown(): void
    {
        if ($this->holder->isTransactionActive()) {
            $this->holder->rollBack();
        }
        $this->database->drop();
        parent::tearDown();
    }

    public function testUndoThatTimesOutOnTheGameLockAnswersConcurrentChange(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $admin = TestUserFactory::create($entityManager, 'concchange', 'admin', ['ROLE_ADMIN']);
        $player = TestUserFactory::create($entityManager, 'concchange', 'player', ['ROLE_PLAYER']);
        $game = (new Game())->setDate(new DateTime())->setStatus(GameStatus::Started)->setRound(1);
        $entityManager->persist($game);
        $seat = TestSeatFactory::create($player)->setPosition(1)->setScore(301);
        $game->addGamePlayer($seat);
        $entityManager->persist($seat);
        $entityManager->flush();
        $gameId = (int) $game->getGameId();
        $entityManager->clear();
        $this->client->loginUser($admin);

        // A parallel request holds the game lock and has not committed.
        $this->holder->beginTransaction();
        $this->holder->fetchOne('SELECT game_id FROM game WHERE game_id = ? FOR UPDATE', [$gameId]);

        $this->client->request('DELETE', sprintf('/api/game/%d/throw/delta', $gameId));

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame(
            [
                'success' => false,
                'error' => 'CONCURRENT_CHANGE',
                'message' => 'A concurrent request changed the same data. Retry the request.',
            ],
            json_decode((string) $this->client->getResponse()->getContent(), true),
        );
    }
}
