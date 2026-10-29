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
use App\Repository\GameRepository;
use App\Tests\Support\TestUserFactory;
use DateTime;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Game start and rematch start accept the camelCase settings keys only; the old lowercase
 * keys are unknown fields (#90).
 */
final class StartSettingsKeysEndpointTest extends WebTestCase
{
    private const string USER_PREFIX = 'startkeys';

    private User $admin;

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function acceptedBodies(): iterable
    {
        foreach (['start', 'rematch'] as $endpoint) {
            yield $endpoint . ' camelCase' => [$endpoint, ['startScore' => 501, 'doubleOut' => true, 'tripleOut' => false]];
        }
    }

    /**
     * @return iterable<string, array{string, string, array<string, mixed>}>
     */
    public static function lowercaseBodies(): iterable
    {
        $bodies = [
            'startscore' => ['startscore' => 501],
            'doubleout' => ['doubleout' => true],
            'tripleout' => ['tripleout' => true],
            'all three' => ['startscore' => 501, 'doubleout' => true, 'tripleout' => false],
            'next to the camelCase key' => ['startScore' => 501, 'startscore' => 501],
        ];
        foreach (['start', 'rematch'] as $endpoint) {
            foreach ($bodies as $name => $body) {
                yield $endpoint . ' ' . $name => [$endpoint, $name, $body];
            }
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('acceptedBodies')]
    public function testSettingsAreApplied(string $endpoint, array $body): void
    {
        $client = static::createClient();
        $game = $this->createGame($endpoint);
        $gameId = (int) $game->getGameId();

        $this->post($client, $endpoint, $gameId, $body);

        self::assertTrue($client->getResponse()->isSuccessful(), (string) $client->getResponse()->getContent());
        $started = $this->resultingGame($endpoint, $gameId);
        self::assertSame(GameStatus::Started, $started->getStatus());
        self::assertSame(501, $started->getStartScore());
        self::assertTrue($started->isDoubleOut());
        self::assertFalse($started->isTripleOut());
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('lowercaseBodies')]
    public function testLowercaseKeysAreUnknownFields(string $endpoint, string $name, array $body): void
    {
        $client = static::createClient();
        $game = $this->createGame($endpoint);
        $gameId = (int) $game->getGameId();

        $this->post($client, $endpoint, $gameId, $body);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, (string) $client->getResponse()->getContent());
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

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $unchanged = $entityManager->find(Game::class, $gameId);
        self::assertInstanceOf(Game::class, $unchanged);
        self::assertSame('start' === $endpoint ? GameStatus::Lobby : GameStatus::Finished, $unchanged->getStatus());
        self::assertSame(301, $unchanged->getStartScore());
        self::assertFalse($unchanged->isDoubleOut());
        self::assertFalse($unchanged->isTripleOut());
        self::assertSame(1, (int) $entityManager->getRepository(Game::class)->count(['gameId' => $gameId]));
        $newer = $entityManager->getRepository(Game::class)->createQueryBuilder('g')
            ->select('COUNT(g.gameId)')
            ->andWhere('g.gameId > :id')
            ->setParameter('id', $gameId)
            ->getQuery()
            ->getSingleScalarResult();
        self::assertSame(0, (int) $newer);
    }

    /**
     * @param KernelBrowser        $client
     * @param array<string, mixed> $body
     */
    private function post(KernelBrowser $client, string $endpoint, int $gameId, array $body): void
    {
        $path = 'start' === $endpoint ? '/api/game/%d/start' : '/api/game/%d/rematch/start';
        $client->loginUser($this->admin);
        $client->request(Request::METHOD_POST, sprintf($path, $gameId), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function createGame(string $endpoint): Game
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->admin = TestUserFactory::create($entityManager, self::USER_PREFIX, 'admin', ['ROLE_ADMIN']);
        $first = TestUserFactory::create($entityManager, self::USER_PREFIX, 'first', ['ROLE_PLAYER']);
        $second = TestUserFactory::create($entityManager, self::USER_PREFIX, 'second', ['ROLE_PLAYER']);

        $game = (new Game())
            ->setDate(new DateTime())
            ->setStartScore(301)
            ->setOutRule(\App\Enum\OutRule::Single)
            ->setStatus('start' === $endpoint ? GameStatus::Lobby : GameStatus::Finished);
        if ('rematch' === $endpoint) {
            $game->setFinishedAt(new DateTimeImmutable());
        }
        $entityManager->persist($game);
        foreach ([1 => $first, 2 => $second] as $position => $player) {
            $seat = (new GamePlayers())->setPlayer($player)->setPosition($position)->setScore(0)->setIsWinner(1 === $position);
            $game->addGamePlayer($seat);
            $entityManager->persist($seat);
        }
        $entityManager->flush();

        return $game;
    }

    private function resultingGame(string $endpoint, int $gameId): Game
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $repository = static::getContainer()->get(GameRepository::class);
        if ('start' === $endpoint) {
            $game = $repository->find($gameId);
        } else {
            $game = $repository->createQueryBuilder('g')
                ->andWhere('g.gameId > :id')
                ->setParameter('id', $gameId)
                ->getQuery()
                ->getOneOrNullResult();
        }
        self::assertInstanceOf(Game::class, $game);

        return $game;
    }
}
