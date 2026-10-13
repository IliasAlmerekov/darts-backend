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
 * Game start and rematch start accept camelCase and legacy lowercase settings keys.
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
        $camel = ['startScore' => 501, 'doubleOut' => true, 'tripleOut' => false];
        $lower = ['startscore' => 501, 'doubleout' => true, 'tripleout' => false];
        $mixed = ['startScore' => 501, 'doubleout' => true, 'tripleOut' => false];
        $agreeing = $camel + $lower;

        foreach (['start', 'rematch'] as $endpoint) {
            yield $endpoint . ' camelCase' => [$endpoint, $camel];
            yield $endpoint . ' lowercase' => [$endpoint, $lower];
            yield $endpoint . ' mixed per setting' => [$endpoint, $mixed];
            yield $endpoint . ' both spellings, same values' => [$endpoint, $agreeing];
        }
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function conflictingBodies(): iterable
    {
        foreach (['start', 'rematch'] as $endpoint) {
            yield $endpoint . ' startScore' => [$endpoint, ['startScore' => 501, 'startscore' => 301]];
            yield $endpoint . ' doubleOut' => [$endpoint, ['doubleOut' => true, 'doubleout' => false]];
            yield $endpoint . ' tripleOut' => [$endpoint, ['tripleOut' => true, 'tripleout' => false]];
        }
    }

    /**
     * @param array<string, mixed> $body
     *
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
    #[DataProvider('conflictingBodies')]
    public function testConflictingSpellingsAreRejected(string $endpoint, array $body): void
    {
        $client = static::createClient();
        $game = $this->createGame($endpoint);
        $gameId = (int) $game->getGameId();

        $this->post($client, $endpoint, $gameId, $body);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST, (string) $client->getResponse()->getContent());
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame('REQUEST_CONFLICTING_SETTING_KEYS', $payload['error'] ?? null);
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
            ->setDoubleOut(false)
            ->setTripleOut(false)
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
