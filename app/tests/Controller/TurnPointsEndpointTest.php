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
use App\Tests\Support\TestSeatFactory;
use App\Tests\Support\TestUserFactory;
use DateTime;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * A throw takes the points of the turn's earlier darts from the round state snapshot.
 */
final class TurnPointsEndpointTest extends WebTestCase
{
    private KernelBrowser $client;
    private int $gameId;
    private int $playerId;

    public function testSecondDartOfATurnReadsTheRoundsThrowsForTheSnapshotAndTheAckOnly(): void
    {
        $this->createGameWithActiveScore(301);
        // The first request reboots the kernel, so the profile below holds only its own queries.
        $this->throwDart(20);
        $this->client->enableProfiler();

        $this->throwDart(20);

        $profile = $this->client->getProfile();
        self::assertNotFalse($profile);
        /** @var DoctrineDataCollector $collector */
        $collector = $profile->getCollector('db');
        /** @var list<array{sql:string}> $queries */
        $queries = $collector->getQueries()['default'] ?? [];
        $throwReads = array_filter(
            $queries,
            static fn (array $query): bool => 1 === preg_match('/^\\s*SELECT\\b.*\\bFROM\\s+round_throws\\b/is', $query['sql']),
        );
        self::assertCount(2, $throwReads, implode("\n", array_column($throwReads, 'sql')));
    }

    public function testBustOnALaterDartReturnsTheScoreToWhereTheTurnBegan(): void
    {
        $this->createGameWithActiveScore(50);
        $this->throwDart(20);
        $this->throwDart(20);

        $this->throwDart(20, isTriple: true);

        $this->client->request(Request::METHOD_GET, sprintf('/api/game/%d', $this->gameId));
        self::assertResponseIsSuccessful();
        /** @var array{players:list<array{id:int,score:int,isBust:bool}>} $state */
        $state = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $players = array_column($state['players'], null, 'id');
        self::assertSame(50, $players[$this->playerId]['score']);
        self::assertTrue($players[$this->playerId]['isBust']);
    }

    private function createGameWithActiveScore(int $score): void
    {
        $this->client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = TestUserFactory::create($em, 'turnpoints', 'admin', ['ROLE_ADMIN']);
        $game = (new Game())->setDate(new DateTime())->setStatus(GameStatus::Started)->setRound(1);
        $em->persist($game);
        foreach ([$score, 301] as $index => $seatScore) {
            $player = TestUserFactory::create($em, 'turnpoints', 'player' . $index, ['ROLE_PLAYER']);
            $seat = TestSeatFactory::create($player)->setPosition($index + 1)->setScore($seatScore);
            $game->addGamePlayer($seat);
            $em->persist($seat);
            $em->flush();
            if (0 === $index) {
                $this->playerId = (int) $player->getId();
            }
        }
        $this->gameId = (int) $game->getGameId();
        $this->client->loginUser($admin);
    }

    private function throwDart(int $value, bool $isTriple = false): void
    {
        $this->client->request(
            Request::METHOD_POST,
            sprintf('/api/game/%d/throw/delta', $this->gameId),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['playerId' => $this->playerId, 'value' => $value, 'isTriple' => $isTriple], JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
    }
}
