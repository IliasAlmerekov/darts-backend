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
     * @return iterable<string, array{string, string}>
     */
    public static function removedRoutes(): iterable
    {
        yield 'settings create' => [Request::METHOD_POST, '/api/game/settings'];
        yield 'rematch start' => [Request::METHOD_POST, '/api/game/%d/rematch/start'];
        yield 'game details' => [Request::METHOD_GET, '/api/games/%d'];
        yield 'plural finished' => [Request::METHOD_GET, '/api/games/%d/finished'];
    }

    #[DataProvider('removedRoutes')]
    public function testRemovedRouteAnswersLikeAnUnknownRoute(string $method, string $path): void
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

        $status = $client->getResponse()->getStatusCode();
        self::assertContains($status, [Response::HTTP_NOT_FOUND, Response::HTTP_METHOD_NOT_ALLOWED], (string) $client->getResponse()->getContent());
    }
}
