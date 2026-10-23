<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Service\Game;

use App\Entity\GamePlayers;
use App\Entity\User;
use App\Service\Game\PlayerDisplayName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class PlayerDisplayNameTest extends TestCase
{
    /**
     * @return iterable<string, array{string|null}>
     */
    public static function blankValues(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'spaces only' => ['   '];
    }

    public function testSeatSnapshotWinsTrimmed(): void
    {
        $seat = $this->seat(' Ilias (old) ', 'Ilias', 'ilias');

        self::assertSame('Ilias (old)', PlayerDisplayName::forSeat($seat));
    }

    #[DataProvider('blankValues')]
    public function testBlankSnapshotFallsBackToTheUsersDisplayName(?string $blank): void
    {
        self::assertSame('Mia', PlayerDisplayName::forSeat($this->seat($blank, ' Mia ', 'mia')));
    }

    #[DataProvider('blankValues')]
    public function testBlankDisplayNameFallsBackToTheUsername(?string $blank): void
    {
        self::assertSame('tom', PlayerDisplayName::forSeat($this->seat(null, $blank, ' tom ')));
        self::assertSame('tom', PlayerDisplayName::forUser($this->user($blank, ' tom ')));
    }

    #[DataProvider('blankValues')]
    public function testBlankUsernameLeavesTheNameEmpty(?string $blank): void
    {
        self::assertSame('', PlayerDisplayName::forSeat($this->seat(null, null, $blank)));
        self::assertSame('', PlayerDisplayName::forUser($this->user(null, $blank)));
    }

    public function testOnlySpacesAreTrimmedLikeSqlTrim(): void
    {
        self::assertSame("\tMia", PlayerDisplayName::forSeat($this->seat(" \tMia ", 'Mia', 'mia')));
        self::assertSame("\t", PlayerDisplayName::forUser($this->user(" \t ", 'tom')));
    }

    public function testSeatWithoutAPlayerHasAnEmptyName(): void
    {
        self::assertSame('', PlayerDisplayName::forSeat(new GamePlayers()));
    }

    public function testUserNameIsTheTrimmedDisplayName(): void
    {
        self::assertSame('Mia', PlayerDisplayName::forUser($this->user(' Mia ', 'mia')));
    }

    private function seat(?string $snapshot, ?string $displayName, ?string $username): GamePlayers
    {
        $seat = (new GamePlayers())->setPlayer($this->user($displayName, $username));
        (new ReflectionProperty(GamePlayers::class, 'displayNameSnapshot'))->setValue($seat, $snapshot);

        return $seat;
    }

    private function user(?string $displayName, ?string $username): User
    {
        $user = new User();
        (new ReflectionProperty(User::class, 'displayName'))->setValue($user, $displayName);
        (new ReflectionProperty(User::class, 'username'))->setValue($user, $username);

        return $user;
    }
}
