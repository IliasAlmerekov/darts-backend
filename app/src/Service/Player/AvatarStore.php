<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Service\Player;

use App\Exception\AvatarDirectoryUnavailableException;
use Psr\Log\LoggerInterface;

/** Reads avatars from the configured directory. */
final class AvatarStore implements AvatarStoreInterface
{
    /**
     * @param string          $directory
     * @param LoggerInterface $logger
     */
    public function __construct(private readonly string $directory, private readonly LoggerInterface $logger)
    {
    }

    /**
     * @param string $email
     *
     * @return array{bytes: string, contentType: string}|null
     */
    #[\Override]
    public function find(string $email): ?array
    {
        if (!is_dir($this->directory) || !is_readable($this->directory) || !is_executable($this->directory)) {
            $this->logger->error('Avatar directory is unavailable.');
            throw new AvatarDirectoryUnavailableException();
        }
        if (str_starts_with($email, '.') || str_contains($email, '/') || str_contains($email, "\\")) {
            return null;
        }
        foreach (['png' => 'image/png', 'jpg' => 'image/jpeg'] as $extension => $type) {
            $path = $this->directory.'/'.strtolower($email).'.'.$extension;
            if (!is_file($path)) {
                continue;
            }
            $bytes = @file_get_contents($path);
            if (false === $bytes || (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) !== $type) {
                $this->logger->warning('Avatar file could not be read or has an invalid type.');

                continue;
            }

            return ['bytes' => $bytes, 'contentType' => $type];
        }

        return null;
    }
}
