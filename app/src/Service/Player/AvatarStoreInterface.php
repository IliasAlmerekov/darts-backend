<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Player;

/** Reads avatar images by email. */
interface AvatarStoreInterface
{
    /**
     * @param string $email
     *
     * @return array{bytes: string, contentType: string}|null
     */
    public function find(string $email): ?array;
}
