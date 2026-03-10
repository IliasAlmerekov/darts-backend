<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

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
        $secondPlayerInGame = $this->createUser('player-second');

        $this->persistGamePlayer($game, $playerInGame, position: 2);
        $this->persistGamePlayer($game, $secondPlayerInGame, position: 1);
        $this->persistGamePlayer($otherGame, $playerInOtherGame);
        $this->entityManager->flush();

        $result = $this->repository->findPlayersWithUserInfo($game->getGameId());

        self::assertCount(2, $result);
        self::assertSame($secondPlayerInGame->getId(), (int) $result[0]['id']);
        self::assertSame($secondPlayerInGame->getUsername(), $result[0]['name']);
        self::assertSame(1, $result[0]['position']);
        self::assertSame($playerInGame->getId(), (int) $result[1]['id']);
        self::assertSame($playerInGame->getUsername(), $result[1]['name']);
        self::assertSame(2, $result[1]['position']);
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

    public function testFindPlayersWithUserInfoMarksGuestNames(): void
    {
        $game = $this->createGame();
        $guest = $this->createUser('alex', isGuest: true);
        $this->persistGamePlayer($game, $guest, position: 1);
        $this->entityManager->flush();

        $result = $this->repository->findPlayersWithUserInfo($game->getGameId());

        self::assertCount(1, $result);
        self::assertSame($guest->getId(), (int) $result[0]['id']);
        self::assertSame('alex', $result[0]['name']);
        self::assertTrue($result[0]['isGuest']);
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
        $ids = array_map(static fn(GamePlayers $gp): int => $gp->getGamePlayerId(), $result);
        self::assertContains($gamePlayerOne->getGamePlayerId(), $ids);
        self::assertContains($gamePlayerTwo->getGamePlayerId(), $ids);
    }

    public function testExistsNameInGameUsesSnapshotCaseInsensitively(): void
    {
        $game = $this->createGame();
        $guest = $this->createUser('guest-user', isGuest: true)->setDisplayName('Ignored Name');

        $this->persistGamePlayer($game, $guest, position: 1)
            ->setDisplayNameSnapshot('  Alex Guest  ');
        $this->entityManager->flush();

        self::assertTrue($this->repository->existsNameInGame($game->getGameId(), 'alex guest'));
        self::assertTrue($this->repository->existsNameInGame($game->getGameId(), '  ALEX GUEST  '));
        self::assertFalse($this->repository->existsNameInGame($game->getGameId(), 'someone else'));
    }

    public function testExistsNameInGameFallsBackToUserDisplayNameAndUsername(): void
    {
        $game = $this->createGame();
        $withDisplayName = $this->createUser('login-name');
        $withDisplayName->setDisplayName('Display Alias');
        $withUsernameFallback = $this->createUser('username-only');
        $withUsernameFallback->setDisplayName('');

        $this->persistGamePlayer($game, $withDisplayName, position: 1)
            ->setDisplayNameSnapshot('');
        $this->persistGamePlayer($game, $withUsernameFallback, position: 2)
            ->setDisplayNameSnapshot('');
        $this->entityManager->flush();

        self::assertTrue($this->repository->existsNameInGame($game->getGameId(), 'display alias'));
        self::assertTrue($this->repository->existsNameInGame($game->getGameId(), 'USERNAME-ONLY'));
    }

    public function testFindNextPositionForGameStartsAtOneWhenGameHasNoPlayers(): void
    {
        $game = $this->createGame();
        $this->entityManager->flush();

        self::assertSame(1, $this->repository->findNextPositionForGame($game->getGameId()));
    }

    public function testFindNextPositionForGameUsesPlayerCountWhenExistingPositionsAreNull(): void
    {
        $game = $this->createGame();
        $first = $this->persistGamePlayer($game, $this->createUser('one'), position: 1);
        $second = $this->persistGamePlayer($game, $this->createUser('two'), position: 1);
        (new \ReflectionProperty(GamePlayers::class, 'position'))->setValue($first, null);
        (new \ReflectionProperty(GamePlayers::class, 'position'))->setValue($second, null);
        $this->entityManager->flush();

        self::assertSame(3, $this->repository->findNextPositionForGame($game->getGameId()));
    }

    public function testFindNextPositionForGameUsesHighestExistingPosition(): void
    {
        $game = $this->createGame();
        $this->persistGamePlayer($game, $this->createUser('one'), position: 2);
        $this->persistGamePlayer($game, $this->createUser('two'), position: 10);
        $this->persistGamePlayer($game, $this->createUser('three'), position: 5);
        $this->entityManager->flush();

        self::assertSame(11, $this->repository->findNextPositionForGame($game->getGameId()));
    }

    public function testFindGameStatePlayersByGameIdReturnsScalarShapeForDtoAssembly(): void
    {
        $game = $this->createGame();
        $alpha = $this->createUser('alpha');
        $beta = $this->createUser('beta', isGuest: true)->setDisplayName('Beta Guest');

        $this->persistGamePlayer($game, $alpha, score: 140, position: 2);
        $this->persistGamePlayer($game, $beta, score: 32, position: 1)
            ->setDisplayNameSnapshot('Snapshot Beta');
        $this->entityManager->flush();

        $result = $this->repository->findGameStatePlayersByGameId($game->getGameId());

        self::assertSame([
            [
                'playerId' => $beta->getId(),
                'name' => 'Snapshot Beta',
                'position' => 1,
                'score' => 32,
                'isGuest' => true,
            ],
            [
                'playerId' => $alpha->getId(),
                'name' => 'alpha',
                'position' => 2,
                'score' => 140,
                'isGuest' => false,
            ],
        ], $result);
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

    private function createUser(string $username, bool $isGuest = false): User
    {
        $user = (new User())
            ->setUsername($username)
            ->setEmail($username . '@test.dev')
            ->setPassword('secret')
            ->setIsGuest($isGuest);

        $this->entityManager->persist($user);

        return $user;
    }

    private function persistGamePlayer(Game $game, User $user, ?int $score = null, int $position = 1): GamePlayers
    {
        $gamePlayer = (new GamePlayers())
            ->setGame($game)
            ->setPlayer($user)
            ->setPosition($position)
            ->setScore($score ?? 50);

        $this->entityManager->persist($gamePlayer);

        return $gamePlayer;
    }
}
