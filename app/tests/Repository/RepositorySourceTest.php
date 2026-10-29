<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Repository;

use PHPUnit\Framework\TestCase;

/**
 * `make:entity` writes commented example methods into each new repository (#50).
 */
final class RepositorySourceTest extends TestCase
{
    public function testNoRepositoryKeepsTheMakerExampleMethods(): void
    {
        $files = glob(dirname(__DIR__, 2).'/src/Repository/*.php');
        self::assertNotFalse($files);
        self::assertNotSame([], $files);

        $offences = [];
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            if (str_contains($source, 'findByExampleField') || str_contains($source, 'findOneBySomeField')) {
                $offences[] = basename($file);
            }
        }

        self::assertSame([], $offences, 'Repositories with the maker example methods.');
    }
}
