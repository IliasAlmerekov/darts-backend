<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\StartGameRequest;
use App\Entity\Game;
use App\Entity\GamePlayers;
use App\Entity\User;
use App\Enum\GameStatus;
use App\Exception\Game\GameMustHaveValidPlayerCountException;
use App\Exception\Game\GameStartNotAllowedException;
use App\Repository\RoundRepositoryInterface;
use App\Service\Game\GameSetupService;
use App\Service\Game\GameStartService;
use App\Service\Security\GameAccessServiceInterface;
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
        $game = new Game();
        $player1 = new GamePlayers()->setPlayer(new User()->setUsername('p1'));
        $player2 = new GamePlayers()->setPlayer(new User()->setUsername('p2'));
        $game->addGamePlayer($player1);
        $game->addGamePlayer($player2);

        $dto = new StartGameRequest();
        $dto->startScore = 301;
        $dto->doubleOut = true;
        $dto->tripleOut = false;
        $dto->playerPositions = [1 => 2, 2 => 1];

        $setupService = new GameSetupService();

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $service = new GameStartService($setupService, $em, $this->createAccessService(), $this->createRoundRepository(false));
        $service->start($game, $dto);

        self::assertSame(GameStatus::Started, $game->getStatus());
        self::assertSame(301, $game->getStartScore());
        self::assertTrue($game->isDoubleOut());
        self::assertFalse($game->isTripleOut());
        self::assertSame(1, $game->getRound());
        self::assertCount(1, $game->getRounds());
        self::assertSame(301, $player1->getScore());
        self::assertSame(301, $player2->getScore());
        self::assertSame(1, $player1->getPosition());
        self::assertSame(2, $player2->getPosition());
    }

    public function testStartThrowsWhenNotEnoughPlayers(): void
    {
        $game = new Game();
        $game->addGamePlayer(new GamePlayers());

        $setupService = new GameSetupService();
        $em = $this->createMock(EntityManagerInterface::class);

        $service = new GameStartService($setupService, $em, $this->createAccessService(), $this->createRoundRepository(false));

        $this->expectException(GameMustHaveValidPlayerCountException::class);
        $service->start($game, new StartGameRequest());
    }

    public function testStartThrowsWhenGameAlreadyStarted(): void
    {
        $game = new Game();
        $game->setStatus(GameStatus::Started);
        $game->addGamePlayer(new GamePlayers()->setPlayer(new User()->setUsername('p1')));
        $game->addGamePlayer(new GamePlayers()->setPlayer(new User()->setUsername('p2')));

        $setupService = new GameSetupService();
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $service = new GameStartService($setupService, $em, $this->createAccessService(), $this->createRoundRepository(false));

        $this->expectException(GameStartNotAllowedException::class);
        $service->start($game, new StartGameRequest());
    }

    public function testStartThrowsWhenGameFinished(): void
    {
        $game = new Game();
        $game->setStatus(GameStatus::Finished);
        $game->addGamePlayer(new GamePlayers()->setPlayer(new User()->setUsername('p1')));
        $game->addGamePlayer(new GamePlayers()->setPlayer(new User()->setUsername('p2')));

        $setupService = new GameSetupService();
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $service = new GameStartService($setupService, $em, $this->createAccessService(), $this->createRoundRepository(false));

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

        $setupService = new GameSetupService();
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $roundRepository = $this->createRoundRepositoryForGame(42, true);
        $service = new GameStartService($setupService, $em, $this->createAccessService(), $roundRepository);
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

        $setupService = new GameSetupService();
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $roundRepository = $this->createRoundRepositoryForGame(43, false);
        $service = new GameStartService($setupService, $em, $this->createAccessService(), $roundRepository);
        $service->start($game, $dto);

        self::assertSame(GameStatus::Started, $game->getStatus());
        self::assertSame(43, $game->getGameId());
        self::assertSame(1, $game->getRound());
        self::assertCount(1, $game->getRounds());
        self::assertSame(1, $game->getRounds()->first()->getRoundNumber());
    }

    public function testStartUsesInMemoryRoundsForUnsavedGameWithExistingRounds(): void
    {
        $game = new Game();
        $game->setStatus(GameStatus::Lobby);
        $game->addGamePlayer(new GamePlayers()->setPlayer(new User()->setUsername('p1')));
        $game->addGamePlayer(new GamePlayers()->setPlayer(new User()->setUsername('p2')));
        $game->addRound((new \App\Entity\Round())->setRoundNumber(7)->setStartedAt(new \DateTimeImmutable()));

        $dto = new StartGameRequest();

        $setupService = new GameSetupService();
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $roundRepository = $this->createRoundRepositoryNeverCalled();
        $service = new GameStartService($setupService, $em, $this->createAccessService(), $roundRepository);
        $service->start($game, $dto);

        self::assertSame(GameStatus::Started, $game->getStatus());
        self::assertNull($game->getGameId());
        self::assertNull($game->getRound());
        self::assertCount(1, $game->getRounds());
        self::assertSame(7, $game->getRounds()->first()->getRoundNumber());
    }

    private function createAccessService(): GameAccessServiceInterface
    {
        $access = $this->createMock(GameAccessServiceInterface::class);
        $access->method('assertPlayerInGameOrAdmin')->willReturn(new User());

        return $access;
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

    private function createRoundRepositoryNeverCalled(): RoundRepositoryInterface
    {
        $roundRepository = $this->createMock(RoundRepositoryInterface::class);
        $roundRepository->expects(self::never())
            ->method('existsForGame');

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
