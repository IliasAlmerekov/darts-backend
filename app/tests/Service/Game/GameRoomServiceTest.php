<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\GameSettingsRequest;
use App\Dto\RoomCreateRequest;
use App\Entity\Game;
use App\Entity\User;
use App\Exception\Game\ConflictingOutRulesException;
use App\Service\Game\GameRoomService;
use App\Service\Game\GameSettingsServiceInterface;
use App\Service\Game\OpenLobby;
use App\Service\Game\OpenLobbyServiceInterface;
use App\Service\Persistence\RetryingTransactionInterface;
use App\Service\Player\PlayerManagementServiceInterface;
use App\Service\Security\GameAccessServiceInterface;
use DateTime;
use Doctrine\ORM\Exception\ORMException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class GameRoomServiceTest extends TestCase
{
    private PlayerManagementServiceInterface&MockObject $playerManagementService;
    private GameAccessServiceInterface $gameAccessService;
    private OpenLobbyServiceInterface&MockObject $openLobbyService;
    private GameSettingsServiceInterface&MockObject $gameSettingsService;
    private RetryingTransactionInterface&MockObject $transaction;
    private Game $lobbyGame;
    private bool $inTransaction = false;

    protected function setUp(): void
    {
        $this->playerManagementService = $this->createMock(PlayerManagementServiceInterface::class);
        $this->gameAccessService = $this->createMock(GameAccessServiceInterface::class);
        $this->gameAccessService->method('requireAuthenticatedUser')->willReturn(new User());
        $this->gameAccessService->method('assertAdmin')->willReturn(new User());
        $this->lobbyGame = (new Game())->setGameId(99)->setDate(new DateTime());
        $this->openLobbyService = $this->createMock(OpenLobbyServiceInterface::class);
        $this->gameSettingsService = $this->createMock(GameSettingsServiceInterface::class);
        $this->transaction = $this->createMock(RetryingTransactionInterface::class);
        $this->transaction->method('run')->willReturnCallback(function (\Closure $operation): mixed {
            $this->inTransaction = true;
            try {
                return $operation();
            } finally {
                $this->inTransaction = false;
            }
        });
    }

    /**
     * @throws ORMException
     */
    public function testOpenRoomLeavesAnExistingLobbyUnchanged(): void
    {
        $this->openLobbyService->method('findOrCreate')->willReturn(new OpenLobby($this->lobbyGame, false));
        $this->playerManagementService->expects(self::never())->method('addPlayers');
        $this->gameSettingsService->expects(self::never())->method('applySettings');

        $service = $this->createService();

        $game = $service->openRoom(new RoomCreateRequest(playerIds: [1, 2, 3], excludePlayerIds: [2]));

        self::assertSame($this->lobbyGame, $game);
    }

    /**
     * An open lobby keeps its players and takes the settings from the request (ADR 0003).
     *
     * @throws ORMException
     */
    public function testOpenRoomAppliesTheRequestSettingsToAnExistingLobbyInsideTheTransaction(): void
    {
        $this->openLobbyService->method('findOrCreate')->willReturn(new OpenLobby($this->lobbyGame, false));
        $this->playerManagementService->expects(self::never())->method('addPlayers');
        $this->gameSettingsService->expects(self::once())
            ->method('applySettings')
            ->with(
                $this->lobbyGame,
                $this->callback(function (GameSettingsRequest $dto): bool {
                    self::assertTrue($this->inTransaction, 'The settings are applied outside the transaction.');

                    return 501 === $dto->startScore && true === $dto->doubleOut && false === $dto->tripleOut && null === $dto->outMode;
                })
            );

        $game = $this->createService()->openRoom(new RoomCreateRequest(playerIds: [1], startScore: 501, doubleOut: true, tripleOut: false));

        self::assertSame($this->lobbyGame, $game);
    }

    /**
     * The lobby is created in the same transaction that rejects the settings, so a rejected
     * request leaves no lobby behind.
     *
     * @throws ORMException
     */
    public function testOpenRoomCreatesTheLobbyAndRejectsConflictingSettingsInOneTransaction(): void
    {
        $this->openLobbyService->expects(self::once())->method('findOrCreate')
            ->willReturnCallback(function (): OpenLobby {
                self::assertTrue($this->inTransaction, 'The lobby is created outside the transaction.');

                return new OpenLobby($this->lobbyGame, true);
            });
        $this->gameSettingsService->method('applySettings')
            ->willReturnCallback(function (): void {
                self::assertTrue($this->inTransaction, 'The settings are applied outside the transaction.');

                throw new ConflictingOutRulesException();
            });
        $this->transaction->expects(self::once())->method('run');

        $this->expectException(ConflictingOutRulesException::class);

        $this->createService()->openRoom(new RoomCreateRequest(doubleOut: true, tripleOut: true));
    }

    /**
     * @throws ORMException
     */
    public function testOpenRoomWithoutSettingsAppliesNone(): void
    {
        $this->openLobbyService->method('findOrCreate')->willReturn(new OpenLobby($this->lobbyGame, true));
        $this->gameSettingsService->expects(self::never())->method('applySettings');

        $game = $this->createService()->openRoom(new RoomCreateRequest(playerIds: [], excludePlayerIds: []));

        self::assertSame($this->lobbyGame, $game);
    }

    /**
     * @throws ORMException
     */
    public function testOpenRoomSeatsTheRequestedPlayersInOrderWithoutTheExcludedOnes(): void
    {
        $this->playerManagementService->expects(self::once())
            ->method('addPlayers')
            ->willReturnCallback(function (int $gameId, array $playerIds): void {
                self::assertTrue($this->inTransaction, 'The players are seated outside the transaction.');
                self::assertSame([99, [3, 1]], [$gameId, $playerIds]);
            });

        $this->openLobbyService->method('findOrCreate')->willReturn(new OpenLobby($this->lobbyGame, true));
        $service = $this->createService();

        $game = $service->openRoom(new RoomCreateRequest(playerIds: [3, 2, 1, 3], excludePlayerIds: [2]));

        self::assertSame($this->lobbyGame, $game);
    }

    private function createService(): GameRoomService
    {
        return new GameRoomService(
            $this->playerManagementService,
            $this->gameAccessService,
            $this->openLobbyService,
            $this->transaction,
            $this->gameSettingsService,
        );
    }
}
