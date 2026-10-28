<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;

/**
 * Posts to /api/login the way the frontend does: the fixed `_csrf_token` field and the
 * test host's own Origin (#135). Login CSRF checks the origin, so a bare POST fails.
 */
final class SameOriginLogin
{
    /** The fixed value SameOriginCsrfTokenManager accepts; the origin decides. */
    public const string TOKEN = 'csrf-token';

    /** The test kernel's own origin. */
    public const array SERVER = ['HTTP_ORIGIN' => 'http://localhost'];

    /**
     * @param array<string, string> $server extra server parameters, merged over the same-origin ones
     */
    public static function post(KernelBrowser $client, string $email, string $password, array $server = []): void
    {
        self::postFields($client, ['_username' => $email, '_password' => $password], $server);
    }

    /**
     * @param array<string, string> $fields   form fields; `_csrf_token` is added
     * @param array<string, string> $server extra server parameters, merged over the same-origin ones
     */
    public static function postFields(KernelBrowser $client, array $fields, array $server = []): void
    {
        $client->request(Request::METHOD_POST, '/api/login', $fields + ['_csrf_token' => self::TOKEN], [], $server + self::SERVER);
    }
}
