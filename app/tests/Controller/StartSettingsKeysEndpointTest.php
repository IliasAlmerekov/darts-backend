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
use App\Entity\User;
use App\Enum\GameStatus;
use App\Tests\Support\TestUserFactory;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Game start accepts the camelCase settings keys only; the old lowercase keys are unknown
 * fields (#90).
 */
final class StartSettingsKeysEndpointTest extends WebTestCase
{
    private const string USER_PREFIX = 'startkeys';

    private User $admin;

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function lowercaseBodies(): iterable
    {
        yield 'startscore' => ['startscore', ['startscore' => 501]];
        yield 'doubleout' => ['doubleout', ['doubleout' => true]];
        yield 'tripleout' => ['tripleout', ['tripleout' => true]];
        yield 'all three' => ['all three', ['startscore' => 501, 'doubleout' => true, 'tripleout' => false]];
        yield 'next to the camelCase key' => ['next to the camelCase key', ['startScore' => 501, 'startscore' => 501]];
    }

    public function testSettingsAreApplied(): void
    {
        $client = static::createClient();
        $gameId = (int) $this->createLobby()->getGameId();

        $this->post($client, $gameId, ['startScore' => 501, 'doubleOut' => true, 'tripleOut' => false]);

        self::assertTrue($client->getResponse()->isSuccessful(), (string) $client->getResponse()->getContent());
        $started = $this->reload($gameId);
        self::assertSame(GameStatus::Started, $started->getStatus());
        self::assertSame(501, $started->getStartScore());
        self::assertTrue($started->isDoubleOut());
        self::assertFalse($started->isTripleOut());
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('lowercaseBodies')]
    public function testLowercaseKeysAreUnknownFields(string $name, array $body): void
    {
        $client = static::createClient();
        $gameId = (int) $this->createLobby()->getGameId();

        $this->post($client, $gameId, $body);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $name.': '.(string) $client->getResponse()->getContent());
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame('REQUEST_VALIDATION_FAILED', $payload['error'] ?? null);
        $expected = [];
        foreach (array_keys($body) as $key) {
            if (in_array($key, ['startscore', 'doubleout', 'tripleout'], true)) {
                $expected[] = ['field' => $key, 'code' => 'UNKNOWN_FIELD'];
            }
        }
        $actual = array_map(
            static fn (array $violation): array => ['field' => $violation['field'], 'code' => $violation['code']],
            $payload['violations'] ?? [],
        );
        self::assertEqualsCanonicalizing($expected, $actual);

        $unchanged = $this->reload($gameId);
        self::assertSame(GameStatus::Lobby, $unchanged->getStatus());
        self::assertSame(301, $unchanged->getStartScore());
        self::assertFalse($unchanged->isDoubleOut());
        self::assertFalse($unchanged->isTripleOut());
    }

    /**
     * @param KernelBrowser        $client
     * @param array<string, mixed> $body
     */
    private function post(KernelBrowser $client, int $gameId, array $body): void
    {
        $client->loginUser($this->admin);
        $client->request(Request::METHOD_POST, sprintf('/api/game/%d/start', $gameId), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function createLobby(): Game
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->admin = TestUserFactory::create($entityManager, self::USER_PREFIX, 'admin', ['ROLE_ADMIN']);
        $first = TestUserFactory::create($entityManager, self::USER_PREFIX, 'first', ['ROLE_PLAYER']);
        $second = TestUserFactory::create($entityManager, self::USER_PREFIX, 'second', ['ROLE_PLAYER']);

        $game = (new Game())
            ->setDate(new DateTime())
            ->setStartScore(301)
            ->setOutRule(\App\Enum\OutRule::Single)
            ->setStatus(GameStatus::Lobby);
        $entityManager->persist($game);
        foreach ([1 => $first, 2 => $second] as $position => $player) {
            $seat = (new GamePlayers())->setPlayer($player)->setPosition($position)->setScore(0)->setIsWinner(false);
            $game->addGamePlayer($seat);
            $entityManager->persist($seat);
        }
        $entityManager->flush();

        return $game;
    }

    private function reload(int $gameId): Game
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $game = $entityManager->find(Game::class, $gameId);
        self::assertInstanceOf(Game::class, $game);

        return $game;
    }
}
