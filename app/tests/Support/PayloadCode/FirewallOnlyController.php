<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Support\PayloadCode;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Answers 200 for a path next to /api/ready or the Public profile, so only the firewall can
 * turn it away.
 */
final class FirewallOnlyController
{
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['success' => true]);
    }
}
