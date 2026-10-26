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
use App\Dto\PlayerStatsDto;
use App\Entity\GamePlayers;
use App\Entity\User;
use App\Repository\GamePlayersRepositoryInterface;
use App\Repository\GameRepositoryInterface;
use App\Repository\RoundRepositoryInterface;
use App\Repository\RoundThrowsRepositoryInterface;
use App\Service\Game\GameStatisticsServiceInterface;
use App\Service\Player\PlayerProfileService;
use App\Service\Player\PlayerThrowStatistics;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
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
    private EntityManagerInterface&Stub $entityManager;
    private bool $inTransaction = false;
    private PlayerProfileService $service;

    protected function setUp(): void
    {
        $this->gameStatisticsService = $this->createStub(GameStatisticsServiceInterface::class);
        $this->gameRepository = $this->createStub(GameRepositoryInterface::class);
        $this->gamePlayersRepository = $this->createStub(GamePlayersRepositoryInterface::class);
        $this->roundRepository = $this->createStub(RoundRepositoryInterface::class);
        $this->roundThrowsRepository = $this->createStub(RoundThrowsRepositoryInterface::class);
        $this->entityManager = $this->createStub(EntityManagerInterface::class);
        $this->entityManager->method('wrapInTransaction')->willReturnCallback(function (callable $operation): mixed {
            $this->inTransaction = true;
            try {
                return $operation();
            } finally {
                $this->inTransaction = false;
            }
        });
        $this->service = $this->createService($this->gamePlayersRepository);
    }

    public function testPlayerWithoutFinishedGameGetsEmptyProfile(): void
    {
        $this->gameStatisticsService->method('getPlayerStatsForPlayer')->willReturn(null);
        $this->gameRepository->method('findRecentFinishedForPlayer')->willReturn([]);
        $gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);
        $gamePlayersRepository->expects(self::never())->method('findByGameIds');

        self::assertEquals(
            new PlayerProfileResponseDto(PlayerThrowStatistics::build(0, null, []), [], null),
            $this->createService($gamePlayersRepository)->getProfile($this->createUser(self::PLAYER_ID, 'ilias'))
        );
    }

    public function testStatisticsReadsShareOneTransaction(): void
    {
        $this->gameStatisticsService->method('getPlayerStatsForPlayer')->willReturnCallback(
            fn(): PlayerStatsDto => new PlayerStatsDto(self::PLAYER_ID, 'ilias', $this->inTransaction ? 1 : 0, 40.0)
        );
        $this->roundThrowsRepository->method('findThrowsInCountedGamesForPlayer')->willReturnCallback(
            fn(): array => $this->inTransaction ? [] : [['gameId' => 1, 'roundId' => 1, 'value' => 0, 'isDouble' => false, 'isTriple' => false, 'isBust' => false, 'score' => 301, 'startScore' => 301, 'doubleOut' => false, 'tripleOut' => false, 'placement' => 1]]
        );
        $this->gameRepository->method('findRecentFinishedForPlayer')->willReturn([]);

        $stats = $this->service->getProfile($this->createUser(self::PLAYER_ID, 'ilias'))->stats;

        self::assertSame(1, $stats->gamesPlayed);
        self::assertSame(0, $stats->wins);
    }

    public function testFinishedGameCarriesOwnResultAndParticipantsByPlacement(): void
    {
        $player = $this->createUser(self::PLAYER_ID, 'ilias')
            ->setRegisteredAt(new DateTimeImmutable('2026-03-02T08:15:00+00:00'));
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
                PlayerThrowStatistics::build(4, 37.5, []),
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
                )],
                registeredAt: '2026-03-02T08:15:00+00:00',
            ),
            $this->service->getProfile($player)
        );
    }

    public function testRoundAverageIsZeroWhenThePlayerNeverThrew(): void
    {
        $player = $this->createUser(self::PLAYER_ID, 'ilias');
        $this->gameRepository->method('findRecentFinishedForPlayer')->willReturn([['gameId' => 42, 'finishedAt' => null]]);
        $this->gamePlayersRepository->method('findByGameIds')->willReturn([42 => [$this->createSeat($player, 1, 301)]]);
        $this->roundRepository->method('countFinishedRoundsByGameIds')->willReturn([]);
        $this->roundThrowsRepository->method('getParticipantTotalsForGames')->willReturn([]);

        $game = $this->service->getProfile($player)->recentGames[0];

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
            $this->entityManager,
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
        return (new GamePlayers())->setPlayer($user)->setPlacement($placement)->setScore($score);
    }
}
