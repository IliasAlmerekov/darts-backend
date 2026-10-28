<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Support\PayloadCode;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;

/**
 * Accepts a PayloadCodeRequest; a body that fails validation never reaches it.
 */
final class PayloadCodeController
{
    public function __invoke(#[MapRequestPayload] PayloadCodeRequest $request): JsonResponse
    {
        return new JsonResponse(['success' => true]);
    }
}
