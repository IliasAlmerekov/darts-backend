<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Logging;

use App\Logging\RequestProcessor;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class RequestProcessorTest extends TestCase
{
    public function testExceptionKeepsRedactedStackTrace(): void
    {
        $uuid = '184d6e4a-d451-4bd0-bb08-ed01cbdcd7de';
        $requests = new RequestStack();
        $requests->push(Request::create('/api/invite/join/'.$uuid));
        $handler = new TestHandler();
        $logger = new Logger('request', [$handler], [new RequestProcessor($requests)]);
        $logger->error('Failure', ['exception' => new RuntimeException('Failed for '.$uuid)]);
        $exception = $handler->getRecords()[0]->context['exception'];
        self::assertIsString($exception);
        self::assertStringNotContainsString($uuid, $exception);
        self::assertStringContainsString('RuntimeException: Failed for [redacted]', $exception);
        self::assertStringContainsString('#0 ', $exception);
    }
}
