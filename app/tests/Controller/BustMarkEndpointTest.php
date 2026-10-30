<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Game;
use App\Entity\GamePlayers;
use App\Enum\GameStatus;
use App\Tests\Support\TestUserFactory;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class BustMarkEndpointTest extends WebTestCase
{
    private KernelBrowser $client;
    private int $gameId;
    /** @var list<int> */
    private array $players = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = TestUserFactory::create($em, 'bustmark', 'admin', ['ROLE_ADMIN']);
        $game = (new Game())->setDate(new DateTime())->setStatus(GameStatus::Started)->setRound(1);
        $em->persist($game);
        foreach (['anna', 'boris', 'vika'] as $index => $name) {
            $player = TestUserFactory::create($em, 'bustmark', $name, ['ROLE_PLAYER']);
            $seat = (new GamePlayers())->setPlayer($player)->setPosition($index + 1)->setScore(2 === $index ? 2 : 301);
            $game->addGamePlayer($seat);
            $em->persist($seat);
            $em->flush();
            $this->players[] = (int) $player->getId();
        }
        $em->flush();
        $this->gameId = (int) $game->getGameId();
        $this->client->loginUser($admin);
    }

    public function testBustMarkPersistsAcrossRoundAndClearsAtNextTurn(): void
    {
        foreach ([0, 1] as $player) {
            for ($i = 0; $i < 3; ++$i) {
                $this->throwDart($player, 1);
            }
        }
        $bust = $this->throwDart(2, 3);
        $this->assertMark($bust, 2, true);
        $this->assertGetMark(2, true);
        for ($i = 0; $i < 3; ++$i) {
            $ack = $this->throwDart(0, 1);
            $this->assertMark($ack, 2, true);
            $this->assertGetMark(2, true);
        }
        for ($i = 0; $i < 3; ++$i) {
            $ack = $this->throwDart(1, 1);
            $expected = $i < 2;
            $this->assertMark($ack, 2, $expected);
            $this->assertGetMark(2, $expected);
        }
    }

    public function testUndoBustReturnsDeletedThrowIdAndRestoresMark(): void
    {
        foreach ([0, 1] as $player) {
            for ($i = 0; $i < 3; ++$i) {
                $this->throwDart($player, 1);
            }
        }
        $bust = $this->throwDart(2, 3);
        $this->client->request(Request::METHOD_DELETE, sprintf('/api/game/%d/throw/delta', $this->gameId));
        self::assertResponseIsSuccessful();
        $undo = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($bust['throw']['id'], $undo['undoneThrow']['id']);
        $this->assertMark($undo, 2, false);
        $this->assertGetMark(2, false);
    }

    /** @return array<string, mixed> */
    private function throwDart(int $player, int $value): array
    {
        $this->client->request(Request::METHOD_POST, sprintf('/api/game/%d/throw/delta', $this->gameId), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['playerId' => $this->players[$player], 'value' => $value], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $ack */
    private function assertMark(array $ack, int $player, bool $expected): void
    {
        $found = false;
        foreach ($ack['scoreboardDelta']['changedPlayers'] as $row) {
            self::assertIsBool($row['isBust']);
            if ($row['playerId'] === $this->players[$player]) {
                self::assertSame($expected, $row['isBust']);
                $found = true;
            }
        }
        self::assertTrue($found, 'Participant missing from scoreboard');
    }

    private function assertGetMark(int $player, bool $expected): void
    {
        $this->client->request(Request::METHOD_GET, sprintf('/api/game/%d', $this->gameId));
        self::assertResponseIsSuccessful();
        $state = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        foreach ($state['players'] as $row) {
            if ($row['id'] === $this->players[$player]) {
                self::assertSame($expected, $row['isBust']);
                return;
            }
        }
        self::fail('Participant missing from game');
    }
}
