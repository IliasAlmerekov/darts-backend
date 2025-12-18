<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\Pagination;
use PHPUnit\Framework\TestCase;

final class PaginationTest extends TestCase
{
    public function testNormalizesLimitAndOffset(): void
    {
        $pagination = Pagination::from(limit: 0, offset: -10, defaultLimit: 20, maxLimit: 100);

        self::assertSame(20, $pagination->limit);
        self::assertSame(0, $pagination->offset);
    }

    public function testClampsLimitToMax(): void
    {
        $pagination = Pagination::from(limit: 999, offset: 5, defaultLimit: 20, maxLimit: 100);

        self::assertSame(100, $pagination->limit);
        self::assertSame(5, $pagination->offset);
    }
}

