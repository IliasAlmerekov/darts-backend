<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Service\Player;

use App\Dto\ParticipantTotalsDto;
use App\Dto\PlayerProfileGameDto;
use App\Dto\PlayerProfileParticipantDto;
use App\Dto\PlayerProfileResponseDto;
use App\Dto\PlayerProfileStatsDto;
use App\Dto\PlayerStatsDto;
use App\Entity\GamePlayers;
use App\Entity\User;
use App\Repository\GamePlayersRepositoryInterface;
use App\Repository\GameRepositoryInterface;
use App\Repository\RoundRepositoryInterface;
use App\Repository\RoundThrowsRepositoryInterface;
use App\Service\Game\GameStatisticsServiceInterface;
use App\Service\Player\PlayerProfileService;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class PlayerProfileServiceTest extends TestCase
{
    private const int PLAYER_ID = 1;

    private GameStatisticsServiceInterface&Stub $gameStatisticsService;
    private GameRepositoryInterface&Stub $gameRepository;
    private GamePlayersRepositoryInterface&Stub $gamePlayersRepository;
    private RoundRepositoryInterface&Stub $roundRepository;
    private RoundThrowsRepositoryInterface&Stub $roundThrowsRepository;
    private PlayerProfileService $service;

    protected function setUp(): void
    {
        $this->gameStatisticsService = $this->createStub(GameStatisticsServiceInterface::class);
        $this->gameRepository = $this->createStub(GameRepositoryInterface::class);
        $this->gamePlayersRepository = $this->createStub(GamePlayersRepositoryInterface::class);
        $this->roundRepository = $this->createStub(RoundRepositoryInterface::class);
        $this->roundThrowsRepository = $this->createStub(RoundThrowsRepositoryInterface::class);
        $this->service = $this->createService($this->gamePlayersRepository);
    }

    public function testPlayerWithoutFinishedGameGetsEmptyProfile(): void
    {
        $this->gameStatisticsService->method('getPlayerStatsForPlayer')->willReturn(null);
        $this->gameRepository->method('findRecentFinishedForPlayer')->willReturn([]);
        $gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);
        $gamePlayersRepository->expects(self::never())->method('findByGameIds');

        self::assertEquals(
            new PlayerProfileResponseDto(new PlayerProfileStatsDto(0, null), []),
            $this->createService($gamePlayersRepository)->getProfile(self::PLAYER_ID)
        );
    }

    public function testFinishedGameCarriesOwnResultAndParticipantsByPlacement(): void
    {
        $player = $this->createUser(self::PLAYER_ID, 'ilias');
        $mia = $this->createUser(2, 'mia');
        $tom = $this->createUser(3, 'tom')->setIsGuest(true);
        $finishedAt = new DateTimeImmutable('2026-10-18T17:42:00+00:00');
        $this->gameStatisticsService
            ->method('getPlayerStatsForPlayer')
            ->willReturn(new PlayerStatsDto(self::PLAYER_ID, 'ilias', 4, 37.5));
        $this->gameRepository
            ->method('findRecentFinishedForPlayer')
            ->willReturn([['gameId' => 42, 'finishedAt' => $finishedAt]]);
        $this->gamePlayersRepository->method('findByGameIds')->willReturn([42 => [
            $this->createSeat($player, 2, 40),
            $this->createSeat($tom, 3, 100),
            $this->createSeat($mia, 1, 0),
        ]]);
        $this->roundRepository->method('countFinishedRoundsByGameIds')->willReturn([42 => 2]);
        $this->roundThrowsRepository->method('getParticipantTotalsForGames')->willReturn([42 => [
            self::PLAYER_ID => new ParticipantTotalsDto(lastRound: 2, roundsPlayed: 2, totalScore: 90.0),
            2 => new ParticipantTotalsDto(lastRound: 2, roundsPlayed: 2, totalScore: 301.0),
            3 => new ParticipantTotalsDto(lastRound: 1, roundsPlayed: 1, totalScore: 10.0),
        ]]);

        self::assertEquals(
            new PlayerProfileResponseDto(
                new PlayerProfileStatsDto(4, 37.5),
                [new PlayerProfileGameDto(
                    gameId: 42,
                    finishedAt: '2026-10-18T17:42:00+00:00',
                    placement: 2,
                    roundAverage: 45.0,
                    participantsCount: 3,
                    participants: [
                        new PlayerProfileParticipantDto(playerId: 2, displayName: 'mia', placement: 1, isSelf: false),
                        new PlayerProfileParticipantDto(playerId: self::PLAYER_ID, displayName: 'ilias', placement: 2, isSelf: true),
                        new PlayerProfileParticipantDto(playerId: null, displayName: 'tom', placement: 3, isSelf: false),
                    ],
                )]
            ),
            $this->service->getProfile(self::PLAYER_ID)
        );
    }

    public function testRoundAverageIsZeroWhenThePlayerNeverThrew(): void
    {
        $player = $this->createUser(self::PLAYER_ID, 'ilias');
        $this->gameRepository->method('findRecentFinishedForPlayer')->willReturn([['gameId' => 42, 'finishedAt' => null]]);
        $this->gamePlayersRepository->method('findByGameIds')->willReturn([42 => [$this->createSeat($player, 1, 301)]]);
        $this->roundRepository->method('countFinishedRoundsByGameIds')->willReturn([]);
        $this->roundThrowsRepository->method('getParticipantTotalsForGames')->willReturn([]);

        $game = $this->service->getProfile(self::PLAYER_ID)->recentGames[0];

        self::assertNull($game->finishedAt);
        self::assertSame(0.0, $game->roundAverage);
    }

    private function createService(GamePlayersRepositoryInterface $gamePlayersRepository): PlayerProfileService
    {
        return new PlayerProfileService(
            $this->gameStatisticsService,
            $this->gameRepository,
            $gamePlayersRepository,
            $this->roundRepository,
            $this->roundThrowsRepository,
        );
    }

    private function createUser(int $id, string $username): User
    {
        $user = (new User())->setUsername($username);
        (new ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }

    private function createSeat(User $user, int $placement, int $score): GamePlayers
    {
        return (new GamePlayers())->setPlayer($user)->setPosition($placement)->setScore($score);
    }
}
