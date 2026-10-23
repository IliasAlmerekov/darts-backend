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
use App\Entity\Round;
use App\Entity\RoundThrows;
use App\Entity\User;
use App\Enum\GameStatus;
use App\Repository\GamePlayersRepository;
use App\Repository\GameRepository;
use App\Repository\RoundThrowsRepository;
use App\Service\Game\PlayerDisplayName;
use App\Service\Game\TurnOrder;
use DateTime;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The SQL name expressions and seat orderings compute the same result as PlayerDisplayName and TurnOrder.
 */
final class DisplayNameQueriesTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private Game $game;
    /** @var array<string, GamePlayers> */
    private array $seats = [];
    private string $suffix;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $this->suffix = bin2hex(random_bytes(4));
        $ann = $this->user('ann_'.$this->suffix, " Ann\t ");
        $bob = $this->user(' bob_'.$this->suffix.' ', '   ');
        $cy = $this->user('cy_'.$this->suffix, 'Cy');

        $this->game = (new Game())->setDate(new DateTime())->setStatus(GameStatus::Finished)->setRound(1)->setWinner($ann);
        $this->entityManager->persist($this->game);

        // Bob's seat is created first, so it has the lower seat id but the higher user id.
        $this->seats['bob'] = $this->seat($bob, '', 1);
        $this->seats['ann'] = $this->seat($ann, '  ', 1);
        // Positions are never null through the API; the update below writes one by hand.
        $this->seats['cy'] = $this->seat($cy, ' Cy (joined) ', 2);
        $this->entityManager->flush();
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE game_players SET position = NULL WHERE game_player_id = ?',
            [$this->seats['cy']->getGamePlayerId()],
        );
        (new ReflectionProperty(GamePlayers::class, 'position'))->setValue($this->seats['cy'], null);

        $round = (new Round())->setGame($this->game)->setRoundNumber(1)->setStartedAt(new DateTimeImmutable());
        $this->entityManager->persist($round);
        $throw = (new RoundThrows())
            ->setGame($this->game)
            ->setRound($round)
            ->setPlayer($ann)
            ->setThrowNumber(1)
            ->setValue(20)
            ->setIsBust(false)
            ->setScore(281)
            ->setTimestamp(new DateTimeImmutable());
        $this->entityManager->persist($throw);
        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    public function testPhpRuleTrimsAndSkipsBlankValuesInSeatOrder(): void
    {
        self::assertSame(['bob_'.$this->suffix, "Ann\t", 'Cy (joined)'], array_values($this->expectedNames()));
    }

    public function testSeatQueriesReturnThePhpNameInTurnOrder(): void
    {
        $repository = static::getContainer()->get(GamePlayersRepository::class);
        $gameId = (int) $this->game->getGameId();
        $expectedIds = array_keys($this->expectedNames());

        $withUserInfo = $repository->findPlayersWithUserInfo($gameId);
        self::assertSame($expectedIds, array_column($withUserInfo, 'id'));
        self::assertSame(array_values($this->expectedNames()), array_column($withUserInfo, 'name'));

        $gameState = $repository->findGameStatePlayersByGameId($gameId);
        self::assertSame($expectedIds, array_column($gameState, 'playerId'));
        self::assertSame(array_values($this->expectedNames()), array_column($gameState, 'name'));

        self::assertSame($expectedIds, array_map(
            static fn (GamePlayers $seat): ?int => $seat->getPlayer()?->getId(),
            $repository->findByGameId($gameId),
        ));
        self::assertSame($expectedIds, array_map(
            static fn (GamePlayers $seat): ?int => $seat->getPlayer()?->getId(),
            $repository->findByGameIds([$gameId])[$gameId],
        ));

        foreach ($this->expectedNames() as $name) {
            self::assertTrue($repository->existsNameInGame($gameId, $name), $name);
        }
    }

    public function testLatestThrowAndFinishedOverviewReturnThePhpName(): void
    {
        $annName = PlayerDisplayName::forSeat($this->seats['ann']);
        $gameId = (int) $this->game->getGameId();

        $latestThrow = static::getContainer()->get(RoundThrowsRepository::class)->findLatestForGame($gameId);
        self::assertSame($annName, $latestThrow['playerName'] ?? null);

        $overview = static::getContainer()->get(GameRepository::class)->findFinishedOverview(1, 0);
        self::assertSame($gameId, $overview[0]['id'] ?? null);
        self::assertSame($annName, $overview[0]['winnerName']);
    }

    /**
     * The PHP rule's names, keyed by user id, in turn order.
     *
     * @return array<int, string>
     */
    private function expectedNames(): array
    {
        $seats = array_values($this->seats);
        usort($seats, TurnOrder::compare(...));

        $names = [];
        foreach ($seats as $seat) {
            $names[(int) $seat->getPlayer()?->getId()] = PlayerDisplayName::forSeat($seat);
        }

        return $names;
    }

    private function user(string $username, string $displayName): User
    {
        $user = (new User())
            ->setEmail(sprintf('%s@names.test', bin2hex(random_bytes(6))))
            ->setUsername($username)
            ->setDisplayName($displayName)
            ->setPassword('unused')
            ->setRoles(['ROLE_PLAYER']);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function seat(User $user, string $snapshot, int $position): GamePlayers
    {
        $seat = (new GamePlayers())->setPlayer($user)->setDisplayNameSnapshot($snapshot)->setPosition($position)->setScore(301);
        $this->game->addGamePlayer($seat);
        $this->entityManager->persist($seat);
        $this->entityManager->flush();

        return $seat;
    }
}
