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
use App\Repository\GameRepository;
use App\Tests\Support\TestUserFactory;
use DateTime;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class RematchStartEndpointTest extends WebTestCase
{
    private const string USER_PREFIX = 'rematch';

    public function testRematchStartOfFinishedTwoPlayerGameStartsNewGameWithBothPlayers(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $admin = TestUserFactory::create($entityManager, self::USER_PREFIX, 'admin', ['ROLE_ADMIN']);
        $first = TestUserFactory::create($entityManager, self::USER_PREFIX, 'first', ['ROLE_PLAYER']);
        $second = TestUserFactory::create($entityManager, self::USER_PREFIX, 'second', ['ROLE_PLAYER']);

        $oldGame = (new Game())
            ->setDate(new DateTime())
            ->setStartScore(301)
            ->setDoubleOut(false)
            ->setTripleOut(false)
            ->setStatus(GameStatus::Finished)
            ->setFinishedAt(new DateTimeImmutable());
        $entityManager->persist($oldGame);
        foreach ([1 => $first, 2 => $second] as $position => $player) {
            $seat = (new GamePlayers())->setPlayer($player)->setPosition($position)->setScore(0)->setIsWinner(1 === $position);
            $oldGame->addGamePlayer($seat);
            $entityManager->persist($seat);
        }
        $entityManager->flush();
        $oldGameId = (int) $oldGame->getGameId();

        $client->loginUser($admin);
        $client->request(Request::METHOD_POST, sprintf('/api/game/%d/rematch/start', $oldGameId), [], [], ['CONTENT_TYPE' => 'application/json'], '{}');

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED, (string) $client->getResponse()->getContent());

        $entityManager->clear();
        $newGames = static::getContainer()->get(GameRepository::class)->createQueryBuilder('g')
            ->andWhere('g.gameId > :oldGameId')
            ->setParameter('oldGameId', $oldGameId)
            ->getQuery()
            ->getResult();
        self::assertCount(1, $newGames);
        $newGame = $newGames[0];
        self::assertInstanceOf(Game::class, $newGame);
        self::assertSame(GameStatus::Started, $newGame->getStatus());

        $playerIds = array_map(
            static fn (GamePlayers $seat): ?int => $seat->getPlayer()?->getId(),
            $newGame->getGamePlayers()->toArray(),
        );
        sort($playerIds);
        self::assertSame([$first->getId(), $second->getId()], $playerIds);

        // The old seats ended on 0, so 301 shows that the start set up the copied seats.
        $scores = array_map(
            static fn (GamePlayers $seat): ?int => $seat->getScore(),
            $newGame->getGamePlayers()->toArray(),
        );
        self::assertSame([301, 301], $scores);
    }
}
