<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Service\Player;

use App\Entity\Game;
use App\Entity\GamePlayers;
use App\Entity\User;
use App\Exception\Game\GameIdMissingException;
use App\Exception\Request\UsernameAlreadyTakenException;
use App\Repository\GamePlayersRepositoryInterface;
use App\Service\Player\GuestPlayerService;
use App\Service\Player\PlayerManagementServiceInterface;
use Closure;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class GuestPlayerServiceTest extends TestCase
{
    private GamePlayersRepositoryInterface&MockObject $gamePlayersRepository;
    private PlayerManagementServiceInterface&MockObject $playerManagementService;
    private GuestPlayerService $service;

    protected function setUp(): void
    {
        $this->gamePlayersRepository = $this->createMock(GamePlayersRepositoryInterface::class);
        $this->playerManagementService = $this->createMock(PlayerManagementServiceInterface::class);
        $this->service = new GuestPlayerService($this->gamePlayersRepository, $this->playerManagementService);
    }

    public function testCreateGuestPlayerSeatsANewGuestUser(): void
    {
        $game = (new Game())->setGameId(10);
        $this->gamePlayersRepository
            ->expects(self::once())
            ->method('existsNameInGame')
            ->with(10, 'Alex')
            ->willReturn(false);
        $this->seatTheCreatedPlayer(10);

        $result = $this->service->createGuestPlayer($game, 'Alex');

        self::assertSame(55, $result['playerId']);
        self::assertSame('Alex', $result['name']);
        self::assertSame(2, $result['position']);
        self::assertNull($result['placement']);
        self::assertTrue($result['isGuest']);
    }

    public function testTheGuestUserIsAGuestWithItsDisplayName(): void
    {
        $this->gamePlayersRepository->expects(self::once())->method('existsNameInGame')->willReturn(false);
        $seat = $this->seatTheCreatedPlayer(10);

        $this->service->createGuestPlayer((new Game())->setGameId(10), 'Alex');

        $guest = $seat->getPlayer();
        self::assertInstanceOf(User::class, $guest);
        self::assertSame('Alex', $guest->getDisplayNameRaw());
        self::assertTrue($guest->isGuest());
        self::assertNull($guest->getRegisteredAt());
        self::assertStringStartsWith('guest_', (string) $guest->getUsername());
    }

    public function testCreateGuestPlayerThrowsWhenUsernameTaken(): void
    {
        $this->gamePlayersRepository
            ->expects(self::once())
            ->method('existsNameInGame')
            ->with(10, 'Alex')
            ->willReturn(true);
        $this->seatTheCreatedPlayer(10);

        try {
            $this->service->createGuestPlayer((new Game())->setGameId(10), 'Alex');
            self::fail('Expected exception was not thrown.');
        } catch (UsernameAlreadyTakenException $exception) {
            self::assertSame('Alex', $exception->getUsername());
            self::assertSame(['suggestions' => []], $exception->getExtraFields());
        }
    }

    public function testCreateGuestPlayerChecksTheTrimmedName(): void
    {
        $this->gamePlayersRepository
            ->expects(self::once())
            ->method('existsNameInGame')
            ->with(10, 'Alex')
            ->willReturn(true);
        $this->seatTheCreatedPlayer(10);

        try {
            $this->service->createGuestPlayer((new Game())->setGameId(10), ' Alex ');
            self::fail('Expected exception was not thrown.');
        } catch (UsernameAlreadyTakenException $exception) {
            self::assertSame('Alex', $exception->getUsername());
        }
    }

    public function testCreateGuestPlayerThrowsBeforeWritesWhenGameIdMissing(): void
    {
        $this->gamePlayersRepository->expects(self::never())->method('existsNameInGame');
        $this->playerManagementService->expects(self::never())->method('addNewPlayer');

        $this->expectException(GameIdMissingException::class);

        $this->service->createGuestPlayer(new Game(), 'Alex');
    }

    /**
     * Lets the seat module run the closure, as it does once the seat rules pass, and seat
     * the player it builds at position 2. The flush gives the player id 55.
     *
     * @param int $gameId
     *
     * @return GamePlayers
     */
    private function seatTheCreatedPlayer(int $gameId): GamePlayers
    {
        $seat = (new GamePlayers())->setPosition(2);
        $this->playerManagementService
            ->expects(self::once())
            ->method('addNewPlayer')
            ->with($gameId, self::isInstanceOf(Closure::class))
            ->willReturnCallback(static function (int $gameId, Closure $createPlayer) use ($seat): GamePlayers {
                $player = $createPlayer();
                self::assertInstanceOf(User::class, $player);
                (new \ReflectionProperty(User::class, 'id'))->setValue($player, 55);

                return $seat->setPlayer($player);
            });

        return $seat;
    }
}
