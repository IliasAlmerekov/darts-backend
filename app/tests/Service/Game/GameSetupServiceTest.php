<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Game;
use App\Entity\GamePlayers;
use App\Entity\User;
use App\Service\Game\GameSetupService;
use PHPUnit\Framework\TestCase;
use ReflectionException;
use ReflectionProperty;

final class GameSetupServiceTest extends TestCase
{
    /**
     * @throws ReflectionException
     */
    public function testApplyInitialScoresAndPositionsUsesProvidedOrder(): void
    {
        $game = new Game();
        $game->setStartScore(301);

        $playerA = $this->createGamePlayer(1);
        $playerB = $this->createGamePlayer(2);
        $playerC = $this->createGamePlayer(3);

        $game->addGamePlayer($playerA);
        $game->addGamePlayer($playerB);
        $game->addGamePlayer($playerC);

        $service = new GameSetupService();
        $service->applyInitialScoresAndPositions($game, [2, 3, 1]);

        self::assertSame(301, $playerA->getScore());
        self::assertSame(301, $playerB->getScore());
        self::assertSame(301, $playerC->getScore());

        self::assertSame(3, $playerA->getPosition()); // id 1 mapped to index 2 => pos 3
        self::assertSame(1, $playerB->getPosition()); // id 2 mapped to index 0 => pos 1
        self::assertSame(2, $playerC->getPosition()); // id 3 mapped to index 1 => pos 2
    }

    /**
     * @throws ReflectionException
     */
    public function testApplyInitialScoresAndPositionsFallsBackToDefaultOrder(): void
    {
        $game = new Game();
        $game->setStartScore(501);

        $playerA = $this->createGamePlayer(10);
        $playerB = $this->createGamePlayer(20);

        $game->addGamePlayer($playerA);
        $game->addGamePlayer($playerB);

        $service = new GameSetupService();
        $service->applyInitialScoresAndPositions($game);

        self::assertSame(501, $playerA->getScore());
        self::assertSame(501, $playerB->getScore());

        self::assertSame(1, $playerA->getPosition());
        self::assertSame(2, $playerB->getPosition());
    }

    /**
     * @throws ReflectionException
     */
    public function testApplyInitialScoresAndPositionsKeepsExistingPositions(): void
    {
        $game = new Game();
        $game->setStartScore(401);

        $playerA = $this->createGamePlayer(30)->setPosition(3);
        $playerB = $this->createGamePlayer(40)->setPosition(1);

        $game->addGamePlayer($playerA);
        $game->addGamePlayer($playerB);

        $service = new GameSetupService();
        $service->applyInitialScoresAndPositions($game);

        self::assertSame(401, $playerA->getScore());
        self::assertSame(401, $playerB->getScore());

        self::assertSame(3, $playerA->getPosition());
        self::assertSame(1, $playerB->getPosition());
    }

    /**
     * @throws ReflectionException
     */
    private function createGamePlayer(int $userId): GamePlayers
    {
        $user = new User();
        $this->setPrivateProperty($user, $userId);

        $gamePlayer = new GamePlayers();
        $gamePlayer->setPlayer($user);

        return $gamePlayer;
    }

    /**
     * @throws ReflectionException
     */
    private function setPrivateProperty(object $object, mixed $value): void
    {
        $ref = new ReflectionProperty($object, 'id');
        $ref->setValue($object, $value);
    }
}
