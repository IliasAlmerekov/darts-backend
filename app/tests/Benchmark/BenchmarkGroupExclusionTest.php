<?php

declare(strict_types=1);

namespace App\Tests\Benchmark;

use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Keeps the benchmarks out of the default run: they take seconds and write reports
 * to var/benchmarks on every run.
 */
final class BenchmarkGroupExclusionTest extends TestCase
{
    private const string BENCHMARK_GROUP = 'benchmark';

    public function testDefaultConfigurationExcludesTheBenchmarkAndIgnoreGroups(): void
    {
        $configPath = dirname(__DIR__, 2).'/phpunit.dist.xml';
        self::assertFileExists($configPath);

        $document = new DOMDocument();
        self::assertTrue($document->load($configPath), 'phpunit.dist.xml is not valid XML.');

        $excludedGroups = [];
        $nodes = (new DOMXPath($document))->query('/phpunit/groups/exclude/group');
        self::assertNotFalse($nodes);
        foreach ($nodes as $node) {
            $excludedGroups[] = trim($node->textContent);
        }

        self::assertContains(self::BENCHMARK_GROUP, $excludedGroups, 'phpunit.dist.xml must exclude the benchmark group.');
        self::assertContains('ignore', $excludedGroups, 'phpunit.dist.xml must exclude the ignore group.');
    }

    /**
     * PHPUnit 12 drops the XML exclude list when --exclude-group is passed on the command line.
     */
    public function testCiDoesNotOverrideTheXmlGroupExclusions(): void
    {
        $ciPath = dirname(__DIR__, 3).'/.gitlab-ci.yml';
        if (!is_file($ciPath)) {
            // The compose php container mounts app/ only; CI checks out the whole repository.
            self::markTestSkipped('.gitlab-ci.yml is outside the mounted application root.');
        }

        $ci = file_get_contents($ciPath);
        self::assertNotFalse($ci);
        self::assertStringContainsString('vendor/bin/phpunit', $ci);
        self::assertStringNotContainsString('--exclude-group', $ci, '.gitlab-ci.yml must not pass --exclude-group: it replaces the XML exclusions.');
    }

    public function testEveryBenchmarkClassIsInTheBenchmarkGroup(): void
    {
        $benchmarkFiles = glob(__DIR__.'/*Test.php');
        self::assertNotFalse($benchmarkFiles);

        $checked = 0;
        foreach ($benchmarkFiles as $file) {
            $className = __NAMESPACE__.'\\'.basename($file, '.php');
            if (self::class === $className) {
                continue;
            }

            self::assertTrue(class_exists($className), sprintf('%s does not declare %s.', $file, $className));
            $groups = array_map(
                static fn (\ReflectionAttribute $attribute): string => $attribute->newInstance()->name(),
                (new ReflectionClass($className))->getAttributes(Group::class),
            );

            self::assertContains(self::BENCHMARK_GROUP, $groups, sprintf('%s must carry #[Group(\'benchmark\')].', $className));
            ++$checked;
        }

        self::assertGreaterThan(0, $checked, 'No benchmark classes found.');
    }
}
