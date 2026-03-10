<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Game;
use App\Entity\GamePlayers;
use App\Entity\Round;
use App\Entity\RoundThrows;
use App\Entity\User;
use App\Enum\GameStatus;
use App\Repository\GamePlayersRepositoryInterface;
use App\Repository\RoundThrowsRepositoryInterface;
use App\Service\Game\ActivePlayerResolver;
use App\Service\Game\GameService;
use App\Service\Game\GameStateVersionService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionException;
use ReflectionProperty;

#[AllowMockObjectsWithoutExpectations]
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
        $game->setRound(2);
        $game->setStartScore(301);

        $round1 = new Round();
        $round1->setRoundNumber(1);
        $round1->setGame($game);

        $round = new Round();
        $round->setRoundNumber(2);
        $round->setGame($game);

        // players
        $user1 = (new User())->setUsername('Ilias A');
        $this->setPrivateProperty($user1, 'id', 1);
        $p1 = (new GamePlayers())
            ->setPlayer($user1)
            ->setPosition(1)
            ->setScore(100);

        $user2 = (new User())->setUsername('Ilias T');
        $this->setPrivateProperty($user2, 'id', 2);
        $p2 = (new GamePlayers())
            ->setPlayer($user2)
            ->setPosition(2)
            ->setScore(0); // finished

        $game->addGamePlayer($p1);
        $game->addGamePlayer($p2);

        // current round throws for user1 (2 throws, not bust)
        $throw1 = (new RoundThrows())
            ->setRound($round)
            ->setPlayer($user1)
            ->setThrowNumber(1)
            ->setValue(20)
            ->setIsBust(false)
            ->setIsDouble(false)
            ->setIsTriple(false);
        $throw2 = (new RoundThrows())
            ->setRound($round)
            ->setPlayer($user1)
            ->setThrowNumber(2)
            ->setValue(40)
            ->setIsBust(false)
            ->setIsDouble(false)
            ->setIsTriple(true);

        $historyThrow = (new RoundThrows())
            ->setRound($round1)
            ->setPlayer($user1)
            ->setThrowNumber(1)
            ->setValue(60)
            ->setIsBust(false)
            ->setIsDouble(true)
            ->setIsTriple(false);

        $gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);
        $gamePlayersRepository->method('findGameStatePlayersByGameId')->with(10)->willReturn([
            [
                'playerId' => 1,
                'name' => 'Ilias A',
                'position' => 1,
                'score' => 100,
                'isGuest' => false,
            ],
            [
                'playerId' => 2,
                'name' => 'Ilias T',
                'position' => 2,
                'score' => 0,
                'isGuest' => false,
            ],
        ]);

        $roundThrowsRepository = $this->createMock(RoundThrowsRepositoryInterface::class);
        $this->stubBatchedThrowQueries($roundThrowsRepository, 10, 2, [$historyThrow, $throw1, $throw2]);

        $service = new GameService(
            $gamePlayersRepository,
            $roundThrowsRepository,
            new ActivePlayerResolver($roundThrowsRepository),
            new GameStateVersionService($roundThrowsRepository),
        );
        $dto = $service->createGameDto($game);

        self::assertSame(10, $dto->id);
        self::assertSame(2, $dto->currentRound);
        self::assertSame(1, $dto->activePlayerId); // user1 still active (<3 throws)
        self::assertSame(2, $dto->currentThrowCount);
        self::assertCount(2, $dto->players);

        $firstPlayer = $dto->players[0];
        self::assertSame('Ilias A', $firstPlayer->name);
        self::assertTrue($firstPlayer->isActive);
        self::assertCount(2, $firstPlayer->currentRoundThrows);
        self::assertFalse($firstPlayer->isBust);
        self::assertCount(2, $firstPlayer->roundHistory);
        self::assertSame(1, $firstPlayer->roundHistory[0]['round']);
        self::assertSame(2, $firstPlayer->roundHistory[1]['round']);
    }

    /**
     * @throws ReflectionException
     */
    public function testCreateGameDtoUsesLastThrowBustWhenNoThrowsInCurrentRound(): void
    {
        $game = new Game();
        $this->setPrivateProperty($game, 'gameId', 10);
        $game->setStatus(GameStatus::Started);
        $game->setRound(2);
        $game->setStartScore(301);

        $round1 = new Round();
        $round1->setRoundNumber(1);
        $round1->setGame($game);

        $user1 = (new User())->setUsername('Hugh');
        $this->setPrivateProperty($user1, 'id', 9);
        $p1 = (new GamePlayers())
            ->setPlayer($user1)
            ->setPosition(1)
            ->setScore(26);
        $game->addGamePlayer($p1);

        $gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);
        $gamePlayersRepository->method('findGameStatePlayersByGameId')->with(10)->willReturn([
            [
                'playerId' => 9,
                'name' => 'Hugh',
                'position' => 1,
                'score' => 26,
                'isGuest' => false,
            ],
        ]);

        $bustThrow = (new RoundThrows())
            ->setRound($round1)
            ->setPlayer($user1)
            ->setThrowNumber(3)
            ->setValue(60)
            ->setIsBust(true)
            ->setIsDouble(false)
            ->setIsTriple(true);

        $roundThrowsRepository = $this->createMock(RoundThrowsRepositoryInterface::class);
        $this->stubBatchedThrowQueries($roundThrowsRepository, 10, 2, [$bustThrow]);

        $service = $this->createGameService($gamePlayersRepository, $roundThrowsRepository);
        $dto = $service->createGameDto($game);

        self::assertSame(2, $dto->currentRound);
        self::assertSame(9, $dto->activePlayerId);
        self::assertSame(0, $dto->currentThrowCount);
        self::assertCount(1, $dto->players);
        self::assertTrue($dto->players[0]->isBust);
        self::assertSame(0, $dto->players[0]->throwsInCurrentRound);
        self::assertSame([], $dto->players[0]->currentRoundThrows);
    }

    /**
     * @throws ReflectionException
     */
    public function testCreateGameDtoMarksBustPlayerAndKeepsNextPlayerActive(): void
    {
        $game = new Game();
        $this->setPrivateProperty($game, 'gameId', 11);
        $game->setStatus(GameStatus::Started);
        $game->setRound(4);
        $game->setStartScore(301);

        $round = (new Round())
            ->setRoundNumber(4)
            ->setGame($game);

        $firstUser = (new User())->setUsername('Busted');
        $this->setPrivateProperty($firstUser, 'id', 21);
        $secondUser = (new User())->setUsername('Waiting');
        $this->setPrivateProperty($secondUser, 'id', 22);

        $game->addGamePlayer(
            (new GamePlayers())
                ->setPlayer($firstUser)
                ->setPosition(1)
                ->setScore(81)
        );
        $game->addGamePlayer(
            (new GamePlayers())
                ->setPlayer($secondUser)
                ->setPosition(2)
                ->setScore(120)
        );

        $gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);
        $gamePlayersRepository->method('findGameStatePlayersByGameId')->with(11)->willReturn([
            [
                'playerId' => 21,
                'name' => 'Busted',
                'position' => 1,
                'score' => 81,
                'isGuest' => false,
            ],
            [
                'playerId' => 22,
                'name' => 'Waiting',
                'position' => 2,
                'score' => 120,
                'isGuest' => false,
            ],
        ]);

        $bustThrow = $this->createThrow($round, $firstUser, 1, 180, true, true, false);

        $roundThrowsRepository = $this->createMock(RoundThrowsRepositoryInterface::class);
        $this->stubBatchedThrowQueries($roundThrowsRepository, 11, 4, [$bustThrow]);

        $service = $this->createGameService($gamePlayersRepository, $roundThrowsRepository);
        $dto = $service->createGameDto($game);

        self::assertSame(22, $dto->activePlayerId);
        self::assertSame(0, $dto->currentThrowCount);
        self::assertCount(2, $dto->players);
        self::assertTrue($dto->players[0]->isBust);
        self::assertFalse($dto->players[0]->isActive);
        self::assertSame(1, $dto->players[0]->throwsInCurrentRound);
        self::assertCount(1, $dto->players[0]->currentRoundThrows);
        self::assertSame(180, $dto->players[0]->currentRoundThrows[0]->value);
        self::assertTrue($dto->players[0]->currentRoundThrows[0]->isDouble);
        self::assertTrue($dto->players[0]->currentRoundThrows[0]->isBust);
        self::assertTrue($dto->players[1]->isActive);
        self::assertFalse($dto->players[1]->isBust);
    }

    /**
     * @throws ReflectionException
     */
    public function testCreateGameDtoPreservesSortedPlayersAndFinishedPlayerContract(): void
    {
        $game = new Game();
        $this->setPrivateProperty($game, 'gameId', 12);
        $game->setStatus(GameStatus::Started);
        $game->setRound(3);
        $game->setStartScore(501);

        $round = (new Round())
            ->setRoundNumber(3)
            ->setGame($game);

        $finishedUser = (new User())->setUsername('Winner');
        $this->setPrivateProperty($finishedUser, 'id', 31);
        $middleUser = (new User())->setUsername('Middle');
        $this->setPrivateProperty($middleUser, 'id', 32);
        $lastUser = (new User())->setUsername('Last');
        $this->setPrivateProperty($lastUser, 'id', 33);

        $game->addGamePlayer((new GamePlayers())->setPlayer($finishedUser)->setPosition(1)->setScore(0));
        $game->addGamePlayer((new GamePlayers())->setPlayer($middleUser)->setPosition(2)->setScore(140));
        $game->addGamePlayer((new GamePlayers())->setPlayer($lastUser)->setPosition(3)->setScore(200));

        $gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);
        $gamePlayersRepository->method('findGameStatePlayersByGameId')->with(12)->willReturn([
            [
                'playerId' => 31,
                'name' => 'Winner',
                'position' => 1,
                'score' => 0,
                'isGuest' => false,
            ],
            [
                'playerId' => 32,
                'name' => 'Middle',
                'position' => 2,
                'score' => 140,
                'isGuest' => false,
            ],
            [
                'playerId' => 33,
                'name' => 'Last',
                'position' => 3,
                'score' => 200,
                'isGuest' => true,
            ],
        ]);

        $middleThrow = $this->createThrow($round, $middleUser, 1, 60);
        $lastThrow = $this->createThrow($round, $lastUser, 1, 45, false, false, true);

        $roundThrowsRepository = $this->createMock(RoundThrowsRepositoryInterface::class);
        $this->stubBatchedThrowQueries($roundThrowsRepository, 12, 3, [$middleThrow, $lastThrow]);

        $service = $this->createGameService($gamePlayersRepository, $roundThrowsRepository);
        $dto = $service->createGameDto($game);

        self::assertSame([31, 32, 33], array_map(static fn($player) => $player->id, $dto->players));
        self::assertSame([1, 2, 3], array_map(static fn($player) => $player->position, $dto->players));
        self::assertSame(32, $dto->activePlayerId);

        $finishedPlayer = $dto->players[0];
        self::assertFalse($finishedPlayer->isActive);
        self::assertFalse($finishedPlayer->isBust);
        self::assertSame(0, $finishedPlayer->score);
        self::assertSame(0, $finishedPlayer->throwsInCurrentRound);
        self::assertSame([], $finishedPlayer->currentRoundThrows);
        self::assertSame([], $finishedPlayer->roundHistory);

        self::assertFalse($dto->players[2]->isBust);
        self::assertTrue($dto->players[2]->isGuest);
        self::assertTrue($dto->players[2]->currentRoundThrows[0]->isTriple);
    }

    /**
     * @throws ReflectionException
     */
    public function testCreateGameDtoBuildsLongRoundHistoryInRoundOrder(): void
    {
        $game = new Game();
        $this->setPrivateProperty($game, 'gameId', 13);
        $game->setStatus(GameStatus::Started);
        $game->setRound(13);
        $game->setStartScore(501);

        $player = (new User())->setUsername('History');
        $this->setPrivateProperty($player, 'id', 41);
        $game->addGamePlayer(
            (new GamePlayers())
                ->setPlayer($player)
                ->setPosition(1)
                ->setScore(141)
        );

        $gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);
        $gamePlayersRepository->method('findGameStatePlayersByGameId')->with(13)->willReturn([
            [
                'playerId' => 41,
                'name' => 'History',
                'position' => 1,
                'score' => 141,
                'isGuest' => false,
            ],
        ]);

        $throws = [];
        for ($roundNumber = 1; $roundNumber <= 13; ++$roundNumber) {
            $round = (new Round())
                ->setRoundNumber($roundNumber)
                ->setGame($game);

            $throws[] = $this->createThrow($round, $player, 1, $roundNumber);
            $throws[] = $this->createThrow($round, $player, 2, $roundNumber + 20, 0 === $roundNumber % 3, 0 === $roundNumber % 2, false);
        }

        $roundThrowsRepository = $this->createMock(RoundThrowsRepositoryInterface::class);
        $this->stubBatchedThrowQueries($roundThrowsRepository, 13, 13, $throws);

        $service = $this->createGameService($gamePlayersRepository, $roundThrowsRepository);
        $dto = $service->createGameDto($game);

        self::assertSame(41, $dto->activePlayerId);
        self::assertSame(2, $dto->currentThrowCount);
        self::assertCount(13, $dto->players[0]->roundHistory);
        self::assertSame(range(1, 13), array_map(static fn(array $round): int => $round['round'], $dto->players[0]->roundHistory));
        self::assertCount(2, $dto->players[0]->roundHistory[0]['throws']);
        self::assertSame(1, $dto->players[0]->roundHistory[0]['throws'][0]->value);
        self::assertSame(33, $dto->players[0]->roundHistory[12]['throws'][1]->value);
        self::assertTrue($dto->players[0]->roundHistory[11]['throws'][1]->isBust);
        self::assertFalse($dto->players[0]->roundHistory[12]['throws'][1]->isBust);
    }

    /**
     * @throws ReflectionException
     */
    public function testBuildStateVersionIsStableForSameState(): void
    {
        $game = new Game();
        $this->setPrivateProperty($game, 'gameId', 77);
        $game->setStatus(GameStatus::Lobby);
        $game->setRound(1);
        $game->setStartScore(301);

        $user = (new User())->setUsername('Alex');
        $this->setPrivateProperty($user, 'id', 15);
        $gamePlayer = (new GamePlayers())
            ->setPlayer($user)
            ->setPosition(1)
            ->setScore(301);
        $game->addGamePlayer($gamePlayer);

        $roundThrowsRepository = $this->createMock(RoundThrowsRepositoryInterface::class);
        $roundThrowsRepository->method('findEntityLatestForGame')->willReturn(null);
        $gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);

        $service = new GameService(
            $gamePlayersRepository,
            $roundThrowsRepository,
            new ActivePlayerResolver($roundThrowsRepository),
            new GameStateVersionService($roundThrowsRepository),
        );

        $firstVersion = $service->buildStateVersion($game);
        $secondVersion = $service->buildStateVersion($game);

        self::assertSame($firstVersion, $secondVersion);
    }

    /**
     * @throws ReflectionException
     */
    public function testBuildStateVersionChangesWhenLatestThrowChanges(): void
    {
        $game = new Game();
        $this->setPrivateProperty($game, 'gameId', 88);
        $game->setStatus(GameStatus::Started);
        $game->setRound(2);
        $game->setStartScore(501);

        $user = (new User())->setUsername('Sam');
        $this->setPrivateProperty($user, 'id', 25);
        $gamePlayer = (new GamePlayers())
            ->setPlayer($user)
            ->setPosition(1)
            ->setScore(401);
        $game->addGamePlayer($gamePlayer);

        $roundThrowsRepository = $this->createMock(RoundThrowsRepositoryInterface::class);
        $gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);

        $firstThrow = (new RoundThrows())->setThrowId(10);
        $secondThrow = (new RoundThrows())->setThrowId(11);
        $roundThrowsRepository->method('findEntityLatestForGame')
            ->with(88)
            ->willReturnOnConsecutiveCalls($firstThrow, $secondThrow);

        $service = new GameService(
            $gamePlayersRepository,
            $roundThrowsRepository,
            new ActivePlayerResolver($roundThrowsRepository),
            new GameStateVersionService($roundThrowsRepository),
        );

        $firstVersion = $service->buildStateVersion($game);
        $secondVersion = $service->buildStateVersion($game);

        self::assertNotSame($firstVersion, $secondVersion);
    }

    /**
     * @throws ReflectionException
     */
    public function testCalculateActivePlayerUsesGamePlayerIdWhenPositionsAreEqual(): void
    {
        $game = new Game();
        $this->setPrivateProperty($game, 'gameId', 90);
        $game->setStatus(GameStatus::Started);
        $game->setRound(1);
        $game->setStartScore(301);

        $round = (new Round())
            ->setRoundNumber(1)
            ->setGame($game);

        $userOne = (new User())->setUsername('One');
        $this->setPrivateProperty($userOne, 'id', 1);
        $playerOne = (new GamePlayers())
            ->setPlayer($userOne)
            ->setPosition(1)
            ->setScore(301);
        $this->setPrivateProperty($playerOne, 'gamePlayerId', 20);

        $userTwo = (new User())->setUsername('Two');
        $this->setPrivateProperty($userTwo, 'id', 2);
        $playerTwo = (new GamePlayers())
            ->setPlayer($userTwo)
            ->setPosition(1)
            ->setScore(301);
        $this->setPrivateProperty($playerTwo, 'gamePlayerId', 10);

        $game->addGamePlayer($playerOne);
        $game->addGamePlayer($playerTwo);

        $roundThrowsRepository = $this->createMock(RoundThrowsRepositoryInterface::class);
        $roundThrowsRepository->method('findCurrentRoundStateSnapshot')
            ->with(90, 1)
            ->willReturn([]);
        $gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);

        $service = new GameService(
            $gamePlayersRepository,
            $roundThrowsRepository,
            new ActivePlayerResolver($roundThrowsRepository),
            new GameStateVersionService($roundThrowsRepository),
        );
        $activePlayerId = $service->calculateActivePlayer($game);

        self::assertSame(2, $activePlayerId);
    }

    /**
     * @throws ReflectionException
     */
    public function testCalculateActivePlayerReusesProvidedRoundStateSnapshot(): void
    {
        $game = new Game();
        $this->setPrivateProperty($game, 'gameId', 91);
        $game->setStatus(GameStatus::Started);
        $game->setRound(2);
        $game->setStartScore(301);

        $userOne = (new User())->setUsername('One');
        $this->setPrivateProperty($userOne, 'id', 1);
        $playerOne = (new GamePlayers())
            ->setPlayer($userOne)
            ->setPosition(1)
            ->setScore(301);

        $userTwo = (new User())->setUsername('Two');
        $this->setPrivateProperty($userTwo, 'id', 2);
        $playerTwo = (new GamePlayers())
            ->setPlayer($userTwo)
            ->setPosition(2)
            ->setScore(301);

        $game->addGamePlayer($playerOne);
        $game->addGamePlayer($playerTwo);

        $roundThrowsRepository = $this->createMock(RoundThrowsRepositoryInterface::class);
        $roundThrowsRepository->expects(self::never())
            ->method('findCurrentRoundStateSnapshot');
        $gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);

        $service = new GameService(
            $gamePlayersRepository,
            $roundThrowsRepository,
            new ActivePlayerResolver($roundThrowsRepository),
            new GameStateVersionService($roundThrowsRepository),
        );

        $activePlayerId = $service->calculateActivePlayer($game, [
            1 => [
                'throwsCount' => 3,
                'lastThrowNumber' => 3,
                'lastThrowValue' => 60,
                'lastThrowBust' => false,
            ],
            2 => [
                'throwsCount' => 1,
                'lastThrowNumber' => 1,
                'lastThrowValue' => 20,
                'lastThrowBust' => false,
            ],
        ]);

        self::assertSame(2, $activePlayerId);
    }

    /**
     * @throws ReflectionException
     */
    private function setPrivateProperty(object $object, string $property, mixed $value): void
    {
        $ref = new ReflectionProperty($object, $property);
        $ref->setValue($object, $value);
    }

    /**
     * @param MockObject&GamePlayersRepositoryInterface $gamePlayersRepository
     * @param MockObject&RoundThrowsRepositoryInterface $roundThrowsRepository
     *
     * @return GameService
     */
    private function createGameService(MockObject $gamePlayersRepository, MockObject $roundThrowsRepository): GameService
    {
        return new GameService(
            $gamePlayersRepository,
            $roundThrowsRepository,
            new ActivePlayerResolver($roundThrowsRepository),
            new GameStateVersionService($roundThrowsRepository),
        );
    }

    /**
     * @param Round $round
     * @param User  $player
     * @param int   $throwNumber
     * @param int   $value
     * @param bool  $isBust
     * @param bool  $isDouble
     * @param bool  $isTriple
     *
     * @return RoundThrows
     */
    private function createThrow(Round $round, User $player, int $throwNumber, int $value, bool $isBust = false, bool $isDouble = false, bool $isTriple = false): RoundThrows
    {
        return (new RoundThrows())
            ->setRound($round)
            ->setPlayer($player)
            ->setThrowNumber($throwNumber)
            ->setValue($value)
            ->setIsBust($isBust)
            ->setIsDouble($isDouble)
            ->setIsTriple($isTriple);
    }

    /**
     * @param MockObject&RoundThrowsRepositoryInterface $repository
     * @param list<RoundThrows>                         $throws
     *
     * @return void
     */
    private function stubBatchedThrowQueries(MockObject $repository, int $gameId, int $currentRoundNumber, array $throws): void
    {
        $currentRoundRows = [];
        $historyRows = [];
        $latestByPlayer = [];
        $roundStateSnapshot = [];

        foreach ($throws as $throw) {
            $playerId = $throw->getPlayer()?->getId();
            $roundNumber = $throw->getRound()?->getRoundNumber();
            if (null === $playerId || null === $roundNumber) {
                continue;
            }

            $row = [
                'playerId' => $playerId,
                'roundNumber' => $roundNumber,
                'throwNumber' => $throw->getThrowNumber() ?? 0,
                'value' => $throw->getValue() ?? 0,
                'isDouble' => $throw->isDouble(),
                'isTriple' => $throw->isTriple(),
                'isBust' => $throw->isBust(),
            ];

            $historyRows[] = $row;
            $latestByPlayer[$playerId] = $row;

            if ($roundNumber === $currentRoundNumber) {
                $currentRoundRows[] = $row;
                $roundStateSnapshot[$playerId] = [
                    'throwsCount' => ($roundStateSnapshot[$playerId]['throwsCount'] ?? 0) + 1,
                    'lastThrowNumber' => $row['throwNumber'],
                    'lastThrowValue' => $row['value'],
                    'lastThrowBust' => $row['isBust'],
                ];
            }
        }

        $repository->method('findCurrentRoundThrowsForGamePlayers')
            ->with($gameId, $currentRoundNumber)
            ->willReturn($currentRoundRows);
        $repository->method('findCurrentRoundStateSnapshot')
            ->with($gameId, $currentRoundNumber)
            ->willReturn($roundStateSnapshot);
        $repository->method('findLatestThrowsForGamePlayers')
            ->with($gameId)
            ->willReturn(array_values($latestByPlayer));
        $repository->method('findRoundHistoryForGame')
            ->with($gameId)
            ->willReturn($historyRows);
    }
}
