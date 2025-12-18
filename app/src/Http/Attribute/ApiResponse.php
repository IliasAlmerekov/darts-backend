<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Http\Attribute;

use Attribute;

/**
 * Configures API response serialization and metadata for controllers returning plain data.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class ApiResponse
{
    /**
     * @param int                   $status
     * @param list<string>          $groups
     * @param array<string, string> $headers
     */
    public function __construct(
        public int $status = 200,
        public array $groups = [],
        public array $headers = [],
    ) {
    }
}
