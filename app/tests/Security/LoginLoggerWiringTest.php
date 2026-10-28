<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Security;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;

/**
 * The failure handler logs on the security channel, which prod ships to stderr at warning level.
 */
final class LoginLoggerWiringTest extends WebTestCase
{
    public function testFailedLoginIsLoggedOnTheSecurityChannel(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $logger = static::getContainer()->get('monolog.logger.security');
        self::assertInstanceOf(Logger::class, $logger);
        $handler = new TestHandler();
        $logger->pushHandler($handler);

        $client->request(Request::METHOD_POST, '/api/login', ['_username' => 'nobody@example.invalid', '_password' => 'x'], [], ['REMOTE_ADDR' => '203.0.113.41']);

        $records = array_values(array_filter($handler->getRecords(), static fn ($record): bool => 'Login failed.' === $record->message));
        self::assertCount(1, $records);
        self::assertSame('security', $records[0]->channel);
        self::assertSame(UserNotFoundException::class, $records[0]->context['previous_class']);
    }
}
