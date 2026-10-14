<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Doctrine;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Runs against the migrated test database, as CI does after `doctrine:migrations:migrate`.
 */
final class SchemaValidationTest extends KernelTestCase
{
    public function testMigratedSchemaMatchesTheMapping(): void
    {
        [$exitCode, $output] = $this->runCommand(['command' => 'doctrine:schema:validate']);

        self::assertSame(0, $exitCode, $output);
    }

    /**
     * @param array<string, bool|string> $input
     *
     * @return array{int, string}
     */
    private function runCommand(array $input): array
    {
        $application = new Application(self::bootKernel());
        $application->setAutoExit(false);

        $output = new BufferedOutput();
        $exitCode = $application->run(new ArrayInput($input), $output);

        return [$exitCode, $output->fetch()];
    }
}
