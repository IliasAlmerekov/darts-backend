<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Game;
use App\Enum\GameStatus;
use DateTime;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The database allows at most one game in the lobby (ADR 0003).
 */
final class OpenLobbyIndexTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager->close();
    }

    public function testSecondLobbyGameFailsAtTheDatabase(): void
    {
        $this->persistGame(GameStatus::Lobby);
        $this->entityManager->flush();

        $this->persistGame(GameStatus::Lobby);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->entityManager->flush();
    }

    public function testGamesOutsideTheLobbyAreNotLimited(): void
    {
        $statuses = [
            GameStatus::Lobby,
            GameStatus::Started,
            GameStatus::Started,
            GameStatus::Finished,
            GameStatus::Finished,
            GameStatus::Aborted,
            GameStatus::Aborted,
        ];
        $games = array_map($this->persistGame(...), $statuses);
        $this->entityManager->flush();

        foreach ($games as $game) {
            self::assertNotNull($game->getGameId());
        }
    }

    public function testLobbyCanOpenAgainAfterThePreviousOneLeavesIt(): void
    {
        $first = $this->persistGame(GameStatus::Lobby);
        $this->entityManager->flush();

        $first->setStatus(GameStatus::Started);
        $this->entityManager->flush();
        $second = $this->persistGame(GameStatus::Lobby);
        $this->entityManager->flush();

        self::assertNotNull($second->getGameId());
    }

    private function persistGame(GameStatus $status): Game
    {
        $game = (new Game())
            ->setDate(new DateTime())
            ->setStatus($status);
        $this->entityManager->persist($game);

        return $game;
    }
}
