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
 * Answers 200 for a path next to /api/ready, so only the firewall can turn it away.
 */
final class NextToReadyController
{
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['success' => true]);
    }
}
