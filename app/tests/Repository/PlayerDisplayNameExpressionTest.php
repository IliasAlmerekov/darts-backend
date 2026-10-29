<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Repository\PlayerDisplayNameExpression;
use PHPUnit\Framework\TestCase;

final class PlayerDisplayNameExpressionTest extends TestCase
{
    public function testDqlUsesTheSeatSnapshotFirst(): void
    {
        self::assertSame(
            "COALESCE(NULLIF(TRIM(gp.displayNameSnapshot), ''), NULLIF(TRIM(u.displayName), ''), TRIM(u.username))",
            PlayerDisplayNameExpression::dql('gp', 'u'),
        );
    }

    public function testSqlUsesTheSeatSnapshotFirst(): void
    {
        self::assertSame(
            "COALESCE(NULLIF(TRIM(gp.display_name_snapshot), ''), NULLIF(TRIM(u.display_name), ''), TRIM(u.username))",
            PlayerDisplayNameExpression::sql('gp', 'u'),
        );
    }

    public function testWithoutASeatAliasTheSnapshotIsLeftOut(): void
    {
        self::assertSame("COALESCE(NULLIF(TRIM(u.displayName), ''), TRIM(u.username))", PlayerDisplayNameExpression::dql(null, 'u'));
        self::assertSame("COALESCE(NULLIF(TRIM(u.display_name), ''), TRIM(u.username))", PlayerDisplayNameExpression::sql(null, 'u'));
    }
}
