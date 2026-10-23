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
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * A participant keeps the display name their seat got when they joined, in the undo delta too.
 */
final class UndoPlayerNameEndpointTest extends WebTestCase
{
    public function testUndoneThrowCarriesTheSameNameAsTheThrow(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $admin = TestUserFactory::create($entityManager, 'undoname', 'admin', ['ROLE_ADMIN']);
        $player = TestUserFactory::create($entityManager, 'undoname', 'player', ['ROLE_PLAYER'])->setDisplayName('Joined Name');
        $game = (new Game())->setDate(new DateTime())->setStatus(GameStatus::Started)->setRound(1);
        $entityManager->persist($game);
        $seat = (new GamePlayers())->setPlayer($player)->setPosition(1)->setScore(301);
        $game->addGamePlayer($seat);
        $entityManager->persist($seat);
        $player->setDisplayName('Renamed Later');
        $entityManager->flush();
        $client->loginUser($admin);
        $uri = sprintf('/api/game/%d/throw/delta', (int) $game->getGameId());

        $client->request(Request::METHOD_POST, $uri, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['playerId' => (int) $player->getId(), 'value' => 5], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        /** @var array{throw: array{playerName: string}} $thrown */
        $thrown = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $entityManager->clear();

        $client->request(Request::METHOD_DELETE, $uri);
        self::assertResponseIsSuccessful();
        /** @var array{undoneThrow: array{playerName: string}} $undone */
        $undone = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('Joined Name', $thrown['throw']['playerName']);
        self::assertSame($thrown['throw']['playerName'], $undone['undoneThrow']['playerName']);
    }
}
