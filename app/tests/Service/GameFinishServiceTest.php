<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Game;
use App\Entity\GamePlayers;
use App\Entity\User;
use App\Enum\GameStatus;
use App\Repository\GamePlayersRepositoryInterface;
use App\Repository\RoundRepositoryInterface;
use App\Repository\RoundThrowsRepositoryInterface;
use App\Service\GameFinishService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use ReflectionException;
use ReflectionProperty;

final class GameFinishServiceTest extends TestCase
{
    /**
     * @throws ReflectionException
     */
    public function testFinishGameSetsWinnerPositionsAndAggregates(): void
    {
        $game = new Game();
        $this->setPrivateProperty($game, 'gameId', 42);
        $game->setStatus(GameStatus::Started);

        $user1 = new User()->setUsername('Player 1');
        $this->setPrivateProperty($user1, 'id', 1);
        $player1 = new GamePlayers()
            ->setPlayer($user1)
            ->setScore(0); // already finished

        $user2 = new User()->setUsername('Player 2');
        $this->setPrivateProperty($user2, 'id', 2);
        $player2 = new GamePlayers()
            ->setPlayer($user2)
            ->setScore(10);

        $gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);
        $gamePlayersRepository->method('findByGameId')
            ->with(42)
            ->willReturn([$player1, $player2]);
        $gamePlayersRepository->method('countFinishedPlayers')
            ->with(42)
            ->willReturn(1);

        $roundThrowsRepository = $this->createMock(RoundThrowsRepositoryInterface::class);
        $roundThrowsRepository->method('getRoundsPlayedForGame')
            ->with(42)
            ->willReturn([1 => 5, 2 => 5]);
        $roundThrowsRepository->method('getTotalScoreForGame')
            ->with(42)
            ->willReturn([1 => 301.0, 2 => 291.0]);

        $roundRepository = $this->createMock(RoundRepositoryInterface::class);
        $roundRepository->method('countFinishedRounds')
            ->with(42)
            ->willReturn(5);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $service = new GameFinishService(
            $entityManager,
            $gamePlayersRepository,
            $roundThrowsRepository,
            $roundRepository
        );

        $finishedPlayers = $service->finishGame($game);

        self::assertSame(GameStatus::Finished, $game->getStatus());
        self::assertNotNull($game->getFinishedAt());
        self::assertSame(1, $player1->getPosition());
        self::assertSame(2, $player2->getPosition());
        self::assertSame($user1, $game->getWinner());
        self::assertTrue($player1->isWinner());
        self::assertFalse($player2->isWinner());

        $firstFinished = $finishedPlayers[0];
        self::assertSame(1, $firstFinished['playerId']);
        self::assertSame('Player 1', $firstFinished['username']);
        self::assertSame(1, $firstFinished['position']);
        self::assertSame(5, $firstFinished['roundsPlayed']);
        self::assertSame(301.0 / 5.0, $firstFinished['roundAverage']);
    }

    /**
     * @throws ReflectionException
     */
    private function setPrivateProperty(object $object, string $property, mixed $value): void
    {
        $ref = new ReflectionProperty($object, $property);
        $ref->setValue($object, $value);
    }
}
