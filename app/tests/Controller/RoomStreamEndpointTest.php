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
use App\Tests\Support\TestUserFactory;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * The room stream serves only the lobby: outside it the request gets 204, so EventSource stops reconnecting (#30).
 */
final class RoomStreamEndpointTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $admin = TestUserFactory::create($this->entityManager, 'stream', 'admin', ['ROLE_ADMIN']);
        $this->entityManager->flush();
        $this->client->loginUser($admin);
    }

    /**
     * @return iterable<string, array{GameStatus}>
     */
    public static function statusOutsideTheLobby(): iterable
    {
        yield 'started' => [GameStatus::Started];
        yield 'finished' => [GameStatus::Finished];
        yield 'aborted' => [GameStatus::Aborted];
    }

    #[DataProvider('statusOutsideTheLobby')]
    public function testStreamOnAGameOutsideTheLobbyAnswersNoContent(GameStatus $status): void
    {
        $game = (new Game())->setDate(new DateTime())->setStartScore(301)->setStatus($status)->setRound(1);
        $this->entityManager->persist($game);
        $this->entityManager->flush();

        $this->client->request('GET', sprintf('/api/room/%d/stream', (int) $game->getGameId()));

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertSame('', (string) $this->client->getInternalResponse()->getContent());
    }

    public function testStreamOnAMissingGameAnswersNotFound(): void
    {
        $this->client->request('GET', '/api/room/999999999/stream');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $response = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($response);
        self::assertSame('GAME_NOT_FOUND', $response['error'] ?? null);
    }
}
