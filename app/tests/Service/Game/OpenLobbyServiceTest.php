<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Service\Game;

use App\Entity\Game;
use App\Enum\GameStatus;
use App\Repository\GameRepository;
use App\Repository\GameRepositoryInterface;
use App\Service\Game\OpenLobby;
use App\Service\Game\OpenLobbyService;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[AllowMockObjectsWithoutExpectations]
final class OpenLobbyServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private GameRepository $gameRepository;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->gameRepository = static::getContainer()->get(GameRepository::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager->close();
    }

    public function testCreatesTheLobbyWhenNoGameIsInIt(): void
    {
        $service = new OpenLobbyService($this->gameRepository, $this->entityManager);

        $lobby = $this->inTransaction($service);

        self::assertTrue($lobby->created);
        self::assertSame(GameStatus::Lobby, $lobby->game->getStatus());
        self::assertSame($lobby->game, $this->inTransaction($service)->game);
        self::assertFalse($this->inTransaction($service)->created);
    }

    /**
     * Two requests both read "no lobby" and both insert. The index rejects the second insert,
     * and the second request returns the lobby the first one created.
     */
    public function testReturnsTheOtherRequestsLobbyWhenTheIndexRejectsTheInsert(): void
    {
        $winner = (new Game())->setDate(new DateTime())->setStatus(GameStatus::Lobby);
        $this->entityManager->persist($winner);
        $this->entityManager->flush();
        $gameCount = $this->countGames();

        $staleRepository = $this->createMock(GameRepositoryInterface::class);
        $staleRepository->method('findOpenLobby')->willReturn(null);
        $staleRepository->method('insertLobby')
            ->willReturnCallback(fn (): int => $this->gameRepository->insertLobby());
        $staleRepository->method('findOpenLobbyForShare')
            ->willReturnCallback(fn (): ?Game => $this->gameRepository->findOpenLobbyForShare());
        $service = new OpenLobbyService($staleRepository, $this->entityManager);

        $lobby = $this->inTransaction($service);

        self::assertFalse($lobby->created);
        self::assertSame($winner->getGameId(), $lobby->game->getGameId());
        self::assertSame($gameCount, $this->countGames());

        // The rejected insert leaves the EntityManager open for the rest of the request.
        $lobby->game->setStartScore(501);
        $this->entityManager->flush();
        $this->entityManager->clear();
        self::assertSame(501, $this->entityManager->find(Game::class, $winner->getGameId())?->getStartScore());
    }

    public function testCreatesALobbyWhenTheFoundLobbyStartedBeforeTheLock(): void
    {
        $started = (new Game())->setDate(new DateTime())->setStatus(GameStatus::Lobby);
        $this->entityManager->persist($started);
        $this->entityManager->flush();
        // Another request starts the game after this request read it as the lobby.
        $this->entityManager->getConnection()->update('game', ['status' => GameStatus::Started->value], ['game_id' => $started->getGameId()]);

        $staleRepository = $this->createMock(GameRepositoryInterface::class);
        $staleRepository->method('findOpenLobby')->willReturn($started);
        $staleRepository->method('insertLobby')
            ->willReturnCallback(fn (): int => $this->gameRepository->insertLobby());
        $service = new OpenLobbyService($staleRepository, $this->entityManager);

        $lobby = $this->inTransaction($service);

        self::assertTrue($lobby->created);
        self::assertNotSame($started->getGameId(), $lobby->game->getGameId());
        self::assertSame(GameStatus::Lobby, $lobby->game->getStatus());
    }

    public function testRetriesWhenTheOtherRequestsLobbyStartedBeforeTheLock(): void
    {
        $lobby = (new Game())->setDate(new DateTime())->setStatus(GameStatus::Lobby);
        $started = (new Game())->setDate(new DateTime())->setStatus(GameStatus::Started);
        $this->entityManager->persist($lobby);
        $this->entityManager->persist($started);
        $this->entityManager->flush();

        $staleRepository = $this->createMock(GameRepositoryInterface::class);
        $staleRepository->method('findOpenLobby')->willReturn(null);
        $staleRepository->method('insertLobby')
            ->willReturnCallback(fn (): int => $this->gameRepository->insertLobby());
        // The first locking read still names a lobby that started before this request locked it.
        $staleRepository->method('findOpenLobbyForShare')->willReturnOnConsecutiveCalls($started, $lobby);
        $service = new OpenLobbyService($staleRepository, $this->entityManager);

        $result = $this->inTransaction($service);

        self::assertFalse($result->created);
        self::assertSame($lobby->getGameId(), $result->game->getGameId());
    }

    private function inTransaction(OpenLobbyService $service): OpenLobby
    {
        return $this->entityManager->getConnection()->transactional(static fn (): OpenLobby => $service->findOrCreate());
    }

    private function countGames(): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM game');
    }
}
