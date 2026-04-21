<?php

declare(strict_types=1);

namespace App\Tests\Service\Game;

use App\Entity\Game;
use App\Entity\GamePlayers;
use App\Entity\RoundThrows;
use App\Entity\User;
use App\Enum\GameStatus;
use App\Repository\RoundThrowsRepositoryInterface;
use App\Service\Game\GameStateVersionService;
use PHPUnit\Framework\TestCase;
use ReflectionException;
use ReflectionProperty;

final class GameStateVersionServiceTest extends TestCase
{
    /**
     * @throws ReflectionException
     */
    public function testBuildStateVersionUsesProvidedLatestThrowIdAndStaysStable(): void
    {
        $game = $this->createGame();
        $roundThrowsRepository = $this->createMock(RoundThrowsRepositoryInterface::class);
        $roundThrowsRepository->expects(self::never())
            ->method('findEntityLatestForGame');

        $service = new GameStateVersionService($roundThrowsRepository);

        $firstVersion = $service->buildStateVersion($game, 123);
        $secondVersion = $service->buildStateVersion($game, 123);

        self::assertSame($firstVersion, $secondVersion);
    }

    /**
     * @throws ReflectionException
     */
    public function testBuildStateVersionChangesWhenProvidedLatestThrowIdChanges(): void
    {
        $game = $this->createGame();
        $roundThrowsRepository = $this->createMock(RoundThrowsRepositoryInterface::class);
        $roundThrowsRepository->expects(self::never())
            ->method('findEntityLatestForGame');

        $service = new GameStateVersionService($roundThrowsRepository);

        $firstVersion = $service->buildStateVersion($game, 123);
        $secondVersion = $service->buildStateVersion($game, 124);

        self::assertNotSame($firstVersion, $secondVersion);
    }

    /**
     * @throws ReflectionException
     */
    public function testBuildStateVersionFallsBackToLatestThrowRepositoryLookup(): void
    {
        $game = $this->createGame();

        $latestThrow = (new RoundThrows())
            ->setThrowId(200);

        $roundThrowsRepository = $this->createMock(RoundThrowsRepositoryInterface::class);
        $roundThrowsRepository->expects(self::once())
            ->method('findEntityLatestForGame')
            ->with(77)
            ->willReturn($latestThrow);

        $service = new GameStateVersionService($roundThrowsRepository);

        $versionWithRepositoryFallback = $service->buildStateVersion($game);
        $versionWithKnownLatestThrowId = $service->buildStateVersion($game, 200);

        self::assertSame($versionWithRepositoryFallback, $versionWithKnownLatestThrowId);
    }

    /**
     * @return Game
     *
     * @throws ReflectionException
     */
    private function createGame(): Game
    {
        $game = new Game();
        $this->setPrivateProperty($game, 'gameId', 77);
        $game->setStatus(GameStatus::Started);
        $game->setRound(3);
        $game->setStartScore(301);

        $user = (new User())->setUsername('Alex');
        $this->setPrivateProperty($user, 'id', 15);

        $gamePlayer = (new GamePlayers())
            ->setPlayer($user)
            ->setPosition(1)
            ->setScore(220);

        $game->addGamePlayer($gamePlayer);

        return $game;
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
