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
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Game start and rematch start answer with the started game's full state (#46).
 */
final class GameStartResponseEndpointTest extends WebTestCase
{
    private const string USER_PREFIX = 'startbody';

    private KernelBrowser $client;

    private User $admin;

    public function testStartReturnsTheStartedGameStateAndItsETag(): void
    {
        $this->client = static::createClient();
        $gameId = $this->createGame(GameStatus::Lobby);

        $start = $this->postJson(sprintf('/api/game/%d/start', $gameId));

        self::assertResponseStatusCodeSame(Response::HTTP_OK, (string) $start->getContent());
        $body = $this->decode($start);
        self::assertSame($gameId, $body['id'] ?? null);
        self::assertSame('started', $body['status'] ?? null);
        self::assertCount(2, $body['players'] ?? []);

        $state = $this->getState($gameId);
        self::assertSame($this->decode($state), $body);
        self::assertSame($state->headers->get('ETag'), $start->headers->get('ETag'));
        self::assertSame($state->headers->get('X-Game-State-Version'), $start->headers->get('X-Game-State-Version'));
    }

    public function testRematchStartReturnsTheStartedRematchState(): void
    {
        $this->client = static::createClient();
        $oldGameId = $this->createGame(GameStatus::Finished);

        $start = $this->postJson(sprintf('/api/game/%d/rematch/start', $oldGameId));

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED, (string) $start->getContent());
        $body = $this->decode($start);
        self::assertIsInt($body['id'] ?? null);
        self::assertGreaterThan($oldGameId, $body['id']);
        self::assertSame('started', $body['status'] ?? null);
        self::assertCount(2, $body['players'] ?? []);

        self::assertSame($this->decode($this->getState($body['id'])), $body);
    }

    private function createGame(GameStatus $status): int
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
            ->setStatus($status);
        if (GameStatus::Finished === $status) {
            $game->setFinishedAt(new DateTimeImmutable());
        }
        $entityManager->persist($game);
        foreach ([1 => $first, 2 => $second] as $position => $player) {
            $seat = (new GamePlayers())->setPlayer($player)->setPosition($position)->setScore(0)->setIsWinner(1 === $position);
            $game->addGamePlayer($seat);
            $entityManager->persist($seat);
        }
        $entityManager->flush();

        return (int) $game->getGameId();
    }

    private function postJson(string $path): Response
    {
        $this->client->loginUser($this->admin);
        $this->client->request(Request::METHOD_POST, $path, [], [], ['CONTENT_TYPE' => 'application/json'], '{}');

        return $this->client->getResponse();
    }

    private function getState(int $gameId): Response
    {
        $this->client->request(Request::METHOD_GET, sprintf('/api/game/%d', $gameId));
        self::assertResponseStatusCodeSame(Response::HTTP_OK, (string) $this->client->getResponse()->getContent());

        return $this->client->getResponse();
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }
}
