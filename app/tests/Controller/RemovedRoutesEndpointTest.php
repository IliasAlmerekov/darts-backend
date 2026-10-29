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
use App\Enum\OutRule;
use App\Tests\Support\TestUserFactory;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The frontend never called these routes, so #50 removed them. They answer like any
 * unknown route, even for an admin and a finished game they used to accept.
 */
final class RemovedRoutesEndpointTest extends WebTestCase
{
    private const string USER_PREFIX = 'removedroutes';

    /**
     * @return iterable<string, array{string, string, int, string}>
     */
    public static function removedRoutes(): iterable
    {
        yield 'legacy throw' => [Request::METHOD_POST, '/api/game/%d/throw', Response::HTTP_NOT_FOUND, 'NOT_FOUND'];
        yield 'legacy undo' => [Request::METHOD_DELETE, '/api/game/%d/throw', Response::HTTP_NOT_FOUND, 'NOT_FOUND'];
        // 405: `GET /api/game/{gameId}` (app_game_state) shares the path, with `settings` as the id.
        yield 'settings create' => [Request::METHOD_POST, '/api/game/settings', Response::HTTP_METHOD_NOT_ALLOWED, 'METHOD_NOT_ALLOWED'];
        yield 'rematch start' => [Request::METHOD_POST, '/api/game/%d/rematch/start', Response::HTTP_NOT_FOUND, 'NOT_FOUND'];
        yield 'game details' => [Request::METHOD_GET, '/api/games/%d', Response::HTTP_NOT_FOUND, 'NOT_FOUND'];
        yield 'plural finished' => [Request::METHOD_GET, '/api/games/%d/finished', Response::HTTP_NOT_FOUND, 'NOT_FOUND'];
    }

    #[DataProvider('removedRoutes')]
    public function testRemovedRouteAnswersLikeAnUnknownRoute(string $method, string $path, int $expectedStatus, string $expectedError): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $admin = TestUserFactory::create($entityManager, self::USER_PREFIX, 'admin', ['ROLE_ADMIN']);
        $player = TestUserFactory::create($entityManager, self::USER_PREFIX, 'player', ['ROLE_PLAYER']);
        $game = (new Game())
            ->setDate(new DateTime())
            ->setStartScore(301)
            ->setOutRule(OutRule::Single)
            ->setStatus(GameStatus::Finished);
        $entityManager->persist($game);
        $seat = (new GamePlayers())->setPlayer($player)->setPosition(1)->setScore(0)->setIsWinner(true);
        $game->addGamePlayer($seat);
        $entityManager->persist($seat);
        $entityManager->flush();

        $client->loginUser($admin);
        $client->request($method, sprintf($path, (int) $game->getGameId()), [], [], ['CONTENT_TYPE' => 'application/json'], '{"startScore":501}');

        $content = (string) $client->getResponse()->getContent();
        self::assertSame($expectedStatus, $client->getResponse()->getStatusCode(), $content);
        $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertFalse($data['success'] ?? null);
        self::assertSame($expectedError, $data['error'] ?? null);
    }
}
