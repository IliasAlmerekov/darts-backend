<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\ApiRequest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ApiRequestTest extends TestCase
{
    public function testPathAndAcceptDetermineApiRequest(): void
    {
        self::assertTrue(ApiRequest::matches(Request::create('/api/games')));
        self::assertTrue(ApiRequest::matches(Request::create('/page', server: ['HTTP_ACCEPT' => 'application/json'])));
        self::assertFalse(ApiRequest::matches(Request::create('/page')));
    }
}
