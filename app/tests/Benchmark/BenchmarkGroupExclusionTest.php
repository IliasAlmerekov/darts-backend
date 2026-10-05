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

    public function testDefaultConfigurationExcludesTheBenchmarkGroup(): void
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
