<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\GamePlayers;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class GamePlayersTest extends TestCase
{
    public function testSetPlayerFillsASpacesOnlySnapshot(): void
    {
        $seat = (new GamePlayers())->setDisplayNameSnapshot('   ')->setPlayer((new User())->setUsername('tom'));

        self::assertSame('tom', $seat->getDisplayNameSnapshot());
    }

    public function testSetPlayerKeepsAnExistingSnapshot(): void
    {
        $seat = (new GamePlayers())->setDisplayNameSnapshot('Guest')->setPlayer((new User())->setUsername('tom'));

        self::assertSame('Guest', $seat->getDisplayNameSnapshot());
    }
}
