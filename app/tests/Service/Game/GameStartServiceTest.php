<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\StartGameRequest;
use App\Entity\Game;
use App\Entity\GamePlayers;
use App\Entity\User;
use App\Enum\GameStatus;
use App\Exception\Game\GameMustHaveValidPlayerCountException;
use App\Exception\Game\GameStartNotAllowedException;
use App\Repository\GameRepositoryInterface;
use App\Repository\RoundRepositoryInterface;
use App\Service\Game\GameStartService;
use App\Tests\Support\LockedGameTransactionFake;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use ReflectionException;
use ReflectionProperty;

#[AllowMockObjectsWithoutExpectations]
final class GameStartServiceTest extends TestCase
{
    public function testStartConfiguresGameAndCallsSetup(): void
    {
        $game = (new Game())->setGameId(1);
        $user1 = new User()->setUsername('p1');
        $user2 = new User()->setUsername('p2');
        $this->setPrivateProperty($user1, 'id', 1);
        $this->setPrivateProperty($user2, 'id', 2);
        $player1 = new GamePlayers()->setPlayer($user1);
        $player2 = new GamePlayers()->setPlayer($user2);
        $game->addGamePlayer($player1);
        $game->addGamePlayer($player2);

        $dto = new StartGameRequest();
        $dto->startScore = 301;
        $dto->doubleOut = true;
        $dto->tripleOut = false;
        $dto->playerPositions = [2, 1];

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $service = new GameStartService($em, $this->createRoundRepository(false), $this->createStub(GameRepositoryInterface::class), $lockedGame = new LockedGameTransactionFake($game));
        $service->start($game, $dto);

        self::assertSame(['runWithRetry'], $lockedGame->calls);
        self::assertSame(GameStatus::Started, $game->getStatus());
        self::assertSame(301, $game->getStartScore());
        self::assertTrue($game->isDoubleOut());
        self::assertFalse($game->isTripleOut());
        self::assertSame(1, $game->getRound());
        self::assertCount(1, $game->getRounds());
        self::assertSame(301, $player1->getScore());
        self::assertSame(301, $player2->getScore());
        self::assertSame(2, $player1->getPosition());
        self::assertSame(1, $player2->getPosition());
    }

    public function testStartThrowsWhenNotEnoughPlayers(): void
    {
        $game = (new Game())->setGameId(1);
        $game->addGamePlayer(new GamePlayers());

        $em = $this->createMock(EntityManagerInterface::class);

        $service = new GameStartService($em, $this->createRoundRepository(false), $this->createStub(GameRepositoryInterface::class), new LockedGameTransactionFake($game));

        $this->expectException(GameMustHaveValidPlayerCountException::class);
        $service->start($game, new StartGameRequest());
    }

    public function testStartThrowsWhenGameAlreadyStarted(): void
    {
        $game = (new Game())->setGameId(1);
        $game->setStatus(GameStatus::Started);
        $game->addGamePlayer(new GamePlayers()->setPlayer(new User()->setUsername('p1')));
        $game->addGamePlayer(new GamePlayers()->setPlayer(new User()->setUsername('p2')));

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $service = new GameStartService($em, $this->createRoundRepository(false), $this->createStub(GameRepositoryInterface::class), new LockedGameTransactionFake($game));

        $this->expectException(GameStartNotAllowedException::class);
        $service->start($game, new StartGameRequest());
    }

    public function testStartThrowsWhenGameFinished(): void
    {
        $game = (new Game())->setGameId(1);
        $game->setStatus(GameStatus::Finished);
        $game->addGamePlayer(new GamePlayers()->setPlayer(new User()->setUsername('p1')));
        $game->addGamePlayer(new GamePlayers()->setPlayer(new User()->setUsername('p2')));

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $service = new GameStartService($em, $this->createRoundRepository(false), $this->createStub(GameRepositoryInterface::class), new LockedGameTransactionFake($game));

        $this->expectException(GameStartNotAllowedException::class);
        $service->start($game, new StartGameRequest());
    }

    /**
     * @throws ReflectionException
     */
    public function testStartDoesNotCreateDuplicateFirstRoundForPersistedGameWithExistingRounds(): void
    {
        $game = new Game();
        $this->setPrivateProperty($game, 'gameId', 42);
        $game->setStatus(GameStatus::Lobby);
        $game->addGamePlayer(new GamePlayers()->setPlayer(new User()->setUsername('p1')));
        $game->addGamePlayer(new GamePlayers()->setPlayer(new User()->setUsername('p2')));

        $dto = new StartGameRequest();

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $roundRepository = $this->createRoundRepositoryForGame(42, true);
        $service = new GameStartService($em, $roundRepository, $this->createStub(GameRepositoryInterface::class), new LockedGameTransactionFake($game));
        $service->start($game, $dto);

        self::assertSame(GameStatus::Started, $game->getStatus());
        self::assertSame(42, $game->getGameId());
        self::assertNull($game->getRound());
        self::assertCount(0, $game->getRounds());
    }

    public function testStartCreatesFirstRoundForPersistedGameWithoutExistingRounds(): void
    {
        $game = new Game();
        $this->setPrivateProperty($game, 'gameId', 43);
        $game->setStatus(GameStatus::Lobby);
        $game->addGamePlayer(new GamePlayers()->setPlayer(new User()->setUsername('p1')));
        $game->addGamePlayer(new GamePlayers()->setPlayer(new User()->setUsername('p2')));

        $dto = new StartGameRequest();

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $roundRepository = $this->createRoundRepositoryForGame(43, false);
        $service = new GameStartService($em, $roundRepository, $this->createStub(GameRepositoryInterface::class), new LockedGameTransactionFake($game));
        $service->start($game, $dto);

        self::assertSame(GameStatus::Started, $game->getStatus());
        self::assertSame(43, $game->getGameId());
        self::assertSame(1, $game->getRound());
        self::assertCount(1, $game->getRounds());
        self::assertSame(1, $game->getRounds()->first()->getRoundNumber());
    }

    private function createRoundRepository(bool $exists): RoundRepositoryInterface
    {
        $roundRepository = $this->createMock(RoundRepositoryInterface::class);
        $roundRepository->method('existsForGame')->willReturn($exists);

        return $roundRepository;
    }

    private function createRoundRepositoryForGame(int $gameId, bool $exists): RoundRepositoryInterface
    {
        $roundRepository = $this->createMock(RoundRepositoryInterface::class);
        $roundRepository->expects(self::once())
            ->method('existsForGame')
            ->with($gameId)
            ->willReturn($exists);

        return $roundRepository;
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
