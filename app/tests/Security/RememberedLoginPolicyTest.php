<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use App\Security\RememberedLoginPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RememberedLoginPolicyTest extends TestCase
{
    /**
     * @param list<string> $roles
     */
    #[DataProvider('provideRoles')]
    public function testOnlyPlayersWithoutAdminRoleAreRemembered(array $roles, bool $expected): void
    {
        $user = (new User())->setRoles($roles);

        self::assertSame($expected, (new RememberedLoginPolicy())->isRemembered($user));
    }

    /**
     * @return iterable<string, array{list<string>, bool}>
     */
    public static function provideRoles(): iterable
    {
        yield 'player' => [['ROLE_PLAYER'], true];
        yield 'admin' => [['ROLE_ADMIN'], false];
        yield 'player who is also admin' => [['ROLE_PLAYER', 'ROLE_ADMIN'], false];
        yield 'no stored role' => [[], false];
    }
}
