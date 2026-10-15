<?php

declare(strict_types=1);

namespace App\Security;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

/**
 * Deletes remember-me tokens nobody can use anymore.
 *
 * A browser stops sending an expired cookie, so its row is never consumed or logged out.
 * Without this, every player who returns after a week away leaves one more dead row behind.
 */
final readonly class RememberedLoginTokenPurger
{
    /**
     * @param Connection $connection
     * @param int        $lifetime
     *
     * @psalm-suppress PossiblyUnusedMethod Reason: constructor is used by Symfony autowiring.
     */
    public function __construct(
        private Connection $connection,
        private int $lifetime,
    ) {
    }

    /**
     * @return void
     */
    public function purgeExpired(): void
    {
        $threshold = new DateTimeImmutable(sprintf('-%d seconds', $this->lifetime));

        $this->connection->executeStatement(
            'DELETE FROM rememberme_token WHERE lastUsed < :threshold',
            ['threshold' => $threshold],
            ['threshold' => Types::DATETIME_IMMUTABLE],
        );
    }
}
