<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\StartGameRequest;
use App\Entity\Game;
use App\Entity\GamePlayers;
use App\Entity\User;
use App\Enum\GameStatus;
use App\Service\Game\GameSetupService;
use App\Service\Game\GameStartService;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

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

        $service = new GameStartService($setupService, $em);
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

        $service = new GameStartService($setupService, $em);

        $this->expectException(InvalidArgumentException::class);
        $service->start($game, new StartGameRequest());
    }
}
