<?php

declare(strict_types=1);

namespace App\Tests\Service\Game;

use App\Entity\Game;
use App\Entity\GamePlayers;
use App\Entity\User;
use App\Enum\GameStatus;
use App\Repository\RoundThrowsRepositoryInterface;
use App\Service\Game\GameDeltaService;
use App\Service\Game\GameServiceInterface;
use PHPUnit\Framework\TestCase;
use ReflectionException;
use ReflectionProperty;

final class GameDeltaServiceTest extends TestCase
{
    /**
     * @throws ReflectionException
     */
    public function testBuildThrowAckKeepsThrowValueForBust(): void
    {
        $game = (new Game())
            ->setGameId(44)
            ->setStatus(GameStatus::Started)
            ->setRound(2)
            ->setStartScore(301);

        $player = (new User())
            ->setUsername('Alex')
            ->setDisplayName('Alex');
        $this->setPrivateProperty($player, 'id', 10);

        $gamePlayer = (new GamePlayers())
            ->setPlayer($player)
            ->setPosition(1)
            ->setScore(26);
        $game->addGamePlayer($gamePlayer);

        $roundThrowsRepository = $this->createStub(RoundThrowsRepositoryInterface::class);

        $gameService = $this->createMock(GameServiceInterface::class);
        $gameService->expects(self::once())
            ->method('buildStateVersion')
            ->with($game)
            ->willReturn('state-v2');
        $gameService->expects(self::once())
            ->method('calculateActivePlayer')
            ->with($game)
            ->willReturn(10);

        $service = new GameDeltaService($roundThrowsRepository, $gameService);
        $ack = $service->buildThrowAck($game, [
            'id' => 501,
            'playerId' => 10,
            'playerName' => 'Alex',
            'value' => 25,
            'isDouble' => false,
            'isTriple' => false,
            'isBust' => true,
            'score' => 26,
            'roundNumber' => 2,
            'timestamp' => '2026-02-13T09:00:00+00:00',
        ]);

        self::assertNotNull($ack->throw);
        self::assertTrue($ack->throw->isBust);
        self::assertSame(25, $ack->throw->value);
        self::assertSame(26, $ack->throw->score);
        self::assertCount(1, $ack->scoreboardDelta->changedPlayers);
        self::assertTrue($ack->scoreboardDelta->changedPlayers[0]->isBust ?? false);
    }

    /**
     * @param object $object
     * @param string $property
     * @param mixed  $value
     *
     * @return void
     *
     * @throws ReflectionException
     */
    private function setPrivateProperty(object $object, string $property, mixed $value): void
    {
        $ref = new ReflectionProperty($object, $property);
        $ref->setAccessible(true);
        $ref->setValue($object, $value);
    }
}
