<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Game;
use App\Entity\GamePlayers;
use App\Entity\Round;
use App\Entity\RoundThrows;
use App\Entity\User;
use App\Enum\GameStatus;
use App\Repository\RoundRepositoryInterface;
use App\Repository\RoundThrowsRepositoryInterface;
use App\Service\Game\GameService;
use PHPUnit\Framework\TestCase;
use ReflectionException;
use ReflectionProperty;

final class GameServiceTest extends TestCase
{
    /**
     * @throws ReflectionException
     */
    public function testCreateGameDtoBuildsPlayersAndActivePlayer(): void
    {
        $game = new Game();
        $this->setPrivateProperty($game, 'gameId', 10);
        $game->setStatus(GameStatus::Started);
        $game->setRound(1);
        $game->setStartScore(301);

        $round = new Round();
        $round->setRoundNumber(1);
        $round->setGame($game);

        // players
        $user1 = new User()->setUsername('Ilias A');
        $this->setPrivateProperty($user1, 'id', 1);
        $p1 = new GamePlayers()
            ->setPlayer($user1)
            ->setPosition(1)
            ->setScore(100);

        $user2 = new User()->setUsername('Ilias T');
        $this->setPrivateProperty($user2, 'id', 2);
        $p2 = new GamePlayers()
            ->setPlayer($user2)
            ->setPosition(2)
            ->setScore(0); // finished

        $game->addGamePlayer($p1);
        $game->addGamePlayer($p2);

        // current round throws for user1 (2 throws, not bust)
        $throw1 = new RoundThrows()
            ->setRound($round)
            ->setPlayer($user1)
            ->setThrowNumber(1)
            ->setValue(20)
            ->setIsBust(false)
            ->setIsDouble(false)
            ->setIsTriple(false);
        $throw2 = new RoundThrows()
            ->setRound($round)
            ->setPlayer($user1)
            ->setThrowNumber(2)
            ->setValue(40)
            ->setIsBust(false)
            ->setIsDouble(false)
            ->setIsTriple(true);

        // Create test double for RoundRepository using interface
        $roundRepository = $this->createMock(RoundRepositoryInterface::class);

        $roundRepository->method('findOneBy')
            ->willReturnCallback(
                static function (array $criteria) use ($game, $round): ?Round {
                    if (($criteria['game'] ?? null) === $game && ($criteria['roundNumber'] ?? null) === 1) {
                        return $round;
                    }
                    return null;
                }
            );

        $roundRepository->method('findBy')
            ->willReturn([$round]);

        $roundThrowsRepository = $this->createMock(RoundThrowsRepositoryInterface::class);

        $roundThrowsRepository->method('findBy')
            ->willReturnCallback(
                static function (
                    array $criteria
                ) use ($round, $user1, $throw1, $throw2): array {
                    if (($criteria['round'] ?? null) === $round && ($criteria['player'] ?? null) === $user1) {
                        return [$throw1, $throw2];
                    }
                    return [];
                }
            );

        $service = new GameService($roundRepository, $roundThrowsRepository);
        $dto = $service->createGameDto($game);

        self::assertSame(10, $dto->id);
        self::assertSame(1, $dto->currentRound);
        self::assertSame(1, $dto->activePlayerId); // user1 still active (<3 throws)
        self::assertSame(2, $dto->currentThrowCount);
        self::assertCount(2, $dto->players);

        $firstPlayer = $dto->players[0];
        self::assertSame('Ilias A', $firstPlayer->name);
        self::assertTrue($firstPlayer->isActive);
        self::assertCount(2, $firstPlayer->currentRoundThrows);
        self::assertFalse($firstPlayer->isBust);
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
