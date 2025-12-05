<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\ThrowRequest;
use App\Entity\Game;
use App\Entity\GamePlayers;
use App\Entity\Round;
use App\Entity\User;
use App\Enum\GameStatus;
use App\Repository\GamePlayersRepositoryInterface;
use App\Repository\RoundRepositoryInterface;
use App\Repository\RoundThrowsRepositoryInterface;
use App\Service\GameThrowService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use ReflectionException;
use ReflectionProperty;

final class GameThrowServiceTest extends TestCase
{
    /**
     * @throws ReflectionException
     */
    public function testRecordThrowUpdatesScoreAndPersistsThrow(): void
    {
        $game = new Game();
        $this->setPrivateProperty($game, 'gameId', 10);
        $game->setStartScore(50);
        $game->setRound(1);
        $game->setStatus(GameStatus::Started);

        $round = new Round();
        $round->setRoundNumber(1);
        $round->setGame($game);

        $user1 = new User()->setUsername('Player 1');
        $this->setPrivateProperty($user1, 'id', 1);
        $player1 = new GamePlayers()
            ->setPlayer($user1)
            ->setScore(50)
            ->setPosition(1);
        $game->addGamePlayer($player1);

        $user2 = new User()->setUsername('Player 2');
        $this->setPrivateProperty($user2, 'id', 2);
        $player2 = new GamePlayers()
            ->setPlayer($user2)
            ->setScore(40)
            ->setPosition(2);
        $game->addGamePlayer($player2);

        $dto = new ThrowRequest();
        $dto->playerId = 1;
        $dto->value = 20;
        $dto->isDouble = false;
        $dto->isTriple = false;

        $gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);
        $gamePlayersRepository->method('findOneBy')
            ->with(['game' => 10, 'player' => 1])
            ->willReturn($player1);

        $roundRepository = $this->createMock(RoundRepositoryInterface::class);
        $roundRepository->method('findOneBy')
            ->with(['game' => $game, 'roundNumber' => 1])
            ->willReturn($round);

        $roundThrowsRepository = $this->createMock(RoundThrowsRepositoryInterface::class);
        $roundThrowsRepository->method('count')
            ->willReturnOnConsecutiveCalls(0, 1);
        $roundThrowsRepository->method('findOneBy')
            ->willReturn(null);

        $persistedThrow = null;
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('persist')
            ->with(self::callback(function (object $entity) use (&$persistedThrow): bool {
                $persistedThrow = $entity;

                return true;
            }));
        $entityManager->expects(self::once())->method('flush');

        $service = new GameThrowService(
            $gamePlayersRepository,
            $roundRepository,
            $roundThrowsRepository,
            $entityManager
        );

        $service->recordThrow($game, $dto);

        self::assertSame(30, $player1->getScore());
        self::assertNotNull($persistedThrow);
        self::assertSame(20, $persistedThrow->getValue());
        self::assertSame(30, $persistedThrow->getScore());
        self::assertFalse($persistedThrow->isBust());
        self::assertSame(1, $persistedThrow->getThrowNumber());
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
