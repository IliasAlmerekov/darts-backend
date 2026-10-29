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
use App\Entity\RoundThrows;
use App\Enum\GameStatus;
use App\Enum\OutRule;
use App\Tests\Support\TestUserFactory;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The bust comes from the scoring rules, never from the client's `isBust` (#50).
 */
final class ThrowClientBustFlagEndpointTest extends WebTestCase
{
    private const string USER_PREFIX = 'clientbust';

    public function testAClientBustFlagOnANonBustThrowIsRecordedAsNotBust(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $admin = TestUserFactory::create($entityManager, self::USER_PREFIX, 'admin', ['ROLE_ADMIN']);
        $player = TestUserFactory::create($entityManager, self::USER_PREFIX, 'player', ['ROLE_PLAYER']);
        $game = (new Game())
            ->setDate(new DateTime())
            ->setStartScore(301)
            ->setOutRule(OutRule::Single)
            ->setStatus(GameStatus::Started);
        $entityManager->persist($game);
        $seat = (new GamePlayers())->setPlayer($player)->setPosition(1)->setScore(301)->setIsWinner(false);
        $game->addGamePlayer($seat);
        $entityManager->persist($seat);
        $entityManager->flush();

        $client->loginUser($admin);
        $client->request(
            Request::METHOD_POST,
            sprintf('/api/game/%d/throw/delta', (int) $game->getGameId()),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['playerId' => $player->getId(), 'value' => 20, 'isBust' => true], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_OK, (string) $client->getResponse()->getContent());
        $entityManager->clear();
        $throws = $entityManager->getRepository(RoundThrows::class)->findBy(['game' => $game->getGameId()]);
        self::assertCount(1, $throws);
        self::assertSame(20, $throws[0]->getValue());
        self::assertFalse($throws[0]->isBust());
    }
}
