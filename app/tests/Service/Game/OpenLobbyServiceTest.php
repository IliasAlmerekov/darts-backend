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

        $lobby = $service->findOrCreate();

        self::assertTrue($lobby->created);
        self::assertSame(GameStatus::Lobby, $lobby->game->getStatus());
        self::assertSame($lobby->game, $service->findOrCreate()->game);
        self::assertFalse($service->findOrCreate()->created);
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
        $staleRepository->method('findOpenLobbyForShare')
            ->willReturnCallback(fn (): ?Game => $this->gameRepository->findOpenLobbyForShare());
        $service = new OpenLobbyService($staleRepository, $this->entityManager);

        $lobby = $service->findOrCreate();

        self::assertFalse($lobby->created);
        self::assertSame($winner->getGameId(), $lobby->game->getGameId());
        self::assertSame($gameCount, $this->countGames());

        // The rejected insert leaves the EntityManager open for the rest of the request.
        $lobby->game->setStartScore(501);
        $this->entityManager->flush();
        $this->entityManager->clear();
        self::assertSame(501, $this->entityManager->find(Game::class, $winner->getGameId())?->getStartScore());
    }

    private function countGames(): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM game');
    }
}
