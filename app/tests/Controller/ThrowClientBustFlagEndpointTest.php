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
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A throw request no longer declares `isBust`: the bust comes from the scoring rules,
 * so a client that still sends the key gets 422 UNKNOWN_FIELD and no throw is recorded (#50).
 */
final class ThrowClientBustFlagEndpointTest extends WebTestCase
{
    private const string USER_PREFIX = 'clientbust';

    /**
     * @return iterable<string, array{string}>
     */
    public static function throwPaths(): iterable
    {
        yield 'throw delta' => ['/api/game/%d/throw/delta'];
    }

    #[DataProvider('throwPaths')]
    public function testAThrowCarryingIsBustIsRejectedAndRecordsNothing(string $path): void
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
            sprintf($path, (int) $game->getGameId()),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['playerId' => $player->getId(), 'value' => 20, 'isBust' => true], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, (string) $client->getResponse()->getContent());
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame('REQUEST_VALIDATION_FAILED', $payload['error'] ?? null);
        self::assertSame(
            [['field' => 'isBust', 'code' => 'UNKNOWN_FIELD', 'message' => 'This attribute was not expected.']],
            $payload['violations'] ?? null,
        );
        $entityManager->clear();
        self::assertSame([], $entityManager->getRepository(RoundThrows::class)->findBy(['game' => $game->getGameId()]));
    }
}
