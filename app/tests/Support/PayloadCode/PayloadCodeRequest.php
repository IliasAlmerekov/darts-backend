<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Support\PayloadCode;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * A payload whose `email` constraint overrides the violation code table.
 */
final class PayloadCodeRequest
{
    public function __construct(
        #[Assert\Email(payload: ['code' => 'EMAIL_DOMAIN_NOT_ALLOWED'])]
        public ?string $email = null,
    ) {
    }
}
