<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Service\Game;

use App\Dto\ThrowAckDto;
use App\Dto\ThrowRequest;
use App\Entity\Game;
use App\Entity\Round;
use App\Entity\RoundThrows;
use App\Enum\GameStatus;
use App\Service\Game\GameStateReaderInterface;
use App\Service\Game\GameThrowServiceInterface;
use App\Tests\Support\TestSeatFactory;
use App\Tests\Support\TestUserFactory;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Pins Round::getRoundThrows() against the stored rows. Only a real database shows the stale
 * collection: a new Round holds an ArrayCollection, not the PersistentCollection the flush depends on.
 */
final class GameThrowServiceRoundThrowsTest extends KernelTestCase
{
    private const int START_SCORE = 301;

    private EntityManagerInterface $entityManager;
    private int $gameId;
    private int $playerId;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $admin = TestUserFactory::create($this->entityManager, 'round-throws', 'admin', ['ROLE_ADMIN']);
        $player = TestUserFactory::create($this->entityManager, 'round-throws', 'player', ['ROLE_PLAYER']);
        $game = (new Game())
            ->setDate(new DateTime())
            ->setStatus(GameStatus::Started)
            ->setStartScore(self::START_SCORE)
            ->setRound(1);
        $game->addGamePlayer(TestSeatFactory::create($player)->setPosition(1)->setScore(self::START_SCORE));
        $this->entityManager->persist($game);
        $this->entityManager->flush();

        $this->gameId = (int) $game->getGameId();
        $this->playerId = (int) $player->getId();
        static::getContainer()->get(TokenStorageInterface::class)
            ->setToken(new UsernamePasswordToken($admin, 'main', $admin->getRoles()));
        $this->startNewRequest();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager->close();
    }

    public function testThrowJoinsARoundWhoseThrowsWereAlreadyLoaded(): void
    {
        $this->recordThrow(20);
        $this->startNewRequest();
        $round = $this->findRound(1);
        self::assertCount(1, $round->getRoundThrows()->toArray());

        $ack = $this->recordThrow(20);

        self::assertSame(2, $this->storedThrowCount(1));
        $this->assertRoundHoldsThrow($round, $ack, 2);
    }

    public function testThrowJoinsARoundWhoseThrowsWereNotLoaded(): void
    {
        $this->recordThrow(20);
        $this->startNewRequest();
        $round = $this->findRound(1);

        $ack = $this->recordThrow(20);

        self::assertSame(2, $this->storedThrowCount(1));
        $this->assertRoundHoldsThrow($round, $ack, 2);
    }

    public function testThrowJoinsARoundCreatedInTheSameRequest(): void
    {
        self::assertNull($this->entityManager->getRepository(Round::class)->findOneBy(['game' => $this->gameId]));

        $ack = $this->recordThrow(20);

        self::assertSame(1, $this->storedThrowCount(1));
        $this->assertRoundHoldsThrow($this->findRound(1), $ack, 1);
    }

    public function testThrowKeepsItsResponseRowAndRoundAdvance(): void
    {
        $first = $this->recordThrow(20);
        $this->recordThrow(5);
        $this->recordThrow(1);

        self::assertSame(20, $first->throw?->value);
        self::assertSame(281, $first->throw?->score);
        self::assertSame(1, $first->throw?->roundNumber);
        self::assertFalse($first->throw?->isBust);
        self::assertSame(
            [['throw_number' => 1, 'value' => 20, 'score' => 281, 'is_bust' => 0]],
            $this->entityManager->getConnection()->fetchAllAssociative(
                'SELECT throw_number, value, score, is_bust FROM round_throws
                 WHERE game_id = ? AND throw_number = 1',
                [$this->gameId],
            ),
        );

        $this->startNewRequest();
        self::assertSame(2, $this->entityManager->find(Game::class, $this->gameId)?->getRound());
        self::assertNotNull($this->findRound(1)->getFinishedAt());
        self::assertNull($this->findRound(2)->getFinishedAt());
    }

    private function assertRoundHoldsThrow(Round $round, ThrowAckDto $ack, int $expectedCount): void
    {
        $throwIds = $round->getRoundThrows()->map(static fn (RoundThrows $throw): ?int => $throw->getThrowId())->toArray();

        self::assertCount($expectedCount, $throwIds);
        self::assertContains($ack->throw?->id, $throwIds);
    }

    private function recordThrow(int $value): ThrowAckDto
    {
        $request = new ThrowRequest();
        $request->playerId = $this->playerId;
        $request->value = $value;

        return static::getContainer()->get(GameThrowServiceInterface::class)->recordThrowAck(
            $this->gameId,
            $request,
            static::getContainer()->get(GameStateReaderInterface::class),
        );
    }

    private function findRound(int $roundNumber): Round
    {
        $round = $this->entityManager->getRepository(Round::class)->findOneBy([
            'game' => $this->gameId,
            'roundNumber' => $roundNumber,
        ]);
        self::assertInstanceOf(Round::class, $round);

        return $round;
    }

    private function storedThrowCount(int $roundNumber): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM round_throws t
             INNER JOIN round r ON r.round_id = t.round_id
             WHERE t.game_id = ? AND r.round_number = ?',
            [$this->gameId, $roundNumber],
        );
    }

    private function startNewRequest(): void
    {
        $this->entityManager->clear();
    }
}
