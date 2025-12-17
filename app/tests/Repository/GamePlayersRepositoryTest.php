<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Game;
use App\Entity\GamePlayers;
use App\Entity\User;
use App\Enum\GameStatus;
use App\Repository\GamePlayersRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GamePlayersRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private GamePlayersRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->repository = static::getContainer()->get(GamePlayersRepository::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager->close();
    }

    public function testFindPlayersWithUserInfo(): void
    {
        $game = $this->createGame();
        $otherGame = $this->createGame();

        $playerInGame = $this->createUser('player-in');
        $playerInOtherGame = $this->createUser('player-out');

        $this->persistGamePlayer($game, $playerInGame);
        $this->persistGamePlayer($otherGame, $playerInOtherGame);
        $this->entityManager->flush();

        $result = $this->repository->findPlayersWithUserInfo($game->getGameId());

        self::assertCount(1, $result);
        self::assertSame($playerInGame->getId(), (int) $result[0]['id']);
        self::assertSame($playerInGame->getUsername(), $result[0]['name']);
    }

    public function testIsPlayerInGame(): void
    {
        $game = $this->createGame();
        $user = $this->createUser('member');
        $otherUser = $this->createUser('stranger');

        $this->persistGamePlayer($game, $user);
        $this->entityManager->flush();

        self::assertTrue($this->repository->isPlayerInGame($game->getGameId(), $user->getId()));
        self::assertFalse($this->repository->isPlayerInGame($game->getGameId(), $otherUser->getId()));
    }

    public function testFindByGameId(): void
    {
        $game = $this->createGame();
        $userOne = $this->createUser('one');
        $userTwo = $this->createUser('two');

        $gamePlayerOne = $this->persistGamePlayer($game, $userOne);
        $gamePlayerTwo = $this->persistGamePlayer($game, $userTwo);
        $this->entityManager->flush();

        $result = $this->repository->findByGameId($game->getGameId());

        self::assertCount(2, $result);
        $ids = array_map(static fn (GamePlayers $gp): int => $gp->getGamePlayerId(), $result);
        self::assertContains($gamePlayerOne->getGamePlayerId(), $ids);
        self::assertContains($gamePlayerTwo->getGamePlayerId(), $ids);
    }

    public function testCountFinishedPlayers(): void
    {
        $game = $this->createGame();
        $winner = $this->persistGamePlayer($game, $this->createUser('winner'), score: 0);
        $notFinished = $this->persistGamePlayer($game, $this->createUser('active'), score: 20);
        $this->entityManager->flush();

        $count = $this->repository->countFinishedPlayers($game->getGameId());

        self::assertSame(1, $count);
        self::assertSame(0, $winner->getScore());
        self::assertSame(20, $notFinished->getScore());
    }

    private function createGame(): Game
    {
        $game = (new Game())
            ->setDate(new \DateTime())
            ->setStatus(GameStatus::Started);

        $this->entityManager->persist($game);

        return $game;
    }

    private function createUser(string $username): User
    {
        $user = (new User())
            ->setUsername($username)
            ->setEmail($username . '@test.dev')
            ->setPassword('secret');

        $this->entityManager->persist($user);

        return $user;
    }

    private function persistGamePlayer(Game $game, User $user, ?int $score = null): GamePlayers
    {
        $gamePlayer = (new GamePlayers())
            ->setGame($game)
            ->setPlayer($user)
            ->setPosition(1)
            ->setScore($score ?? 50);

        $this->entityManager->persist($gamePlayer);

        return $gamePlayer;
    }
}
