<?php

declare(strict_types=1);

namespace App\Tests\Benchmark;

use App\Entity\Game;
use App\Entity\GamePlayers;
use App\Entity\Round;
use App\Entity\RoundThrows;
use App\Entity\User;
use App\Enum\GameStatus;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[CoversNothing]
final class GameSettingsReadBaselineTest extends WebTestCase
{
    private const int MEASUREMENT_RUNS = 3;

    private const string OUTPUT_FILE = '/var/benchmarks/be-201-game-settings-baseline.md';

    public function testCollectsBaselineMetricsForGameSettingsReadEndpoint(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $benchmarkUser = $this->createUser($entityManager, 'be201-primary', ['ROLE_PLAYER']);
        $entityManager->flush();

        $scenarioNotes = [
            'lobby_three_players' => '3 players, lobby game, no rounds played yet.',
            'started_medium' => '3 players, started game, 5 finished rounds plus partial current round.',
            'started_long_history' => '4 players, started game, 18 finished rounds plus partial current round.',
        ];

        $definitions = [
            [
                'endpoint' => 'GET /api/game/{id}/settings',
                'scenario' => 'lobby_three_players',
                'comparisonKey' => 'lobby_three_players',
                'method' => Request::METHOD_GET,
                'builder' => function (EntityManagerInterface $entityManager, User $benchmarkUser, int $run): array {
                    $game = $this->createLobbyGameScenario(
                        $entityManager,
                        $benchmarkUser,
                        sprintf('be201-lobby-%d', $run),
                        3,
                    );
                    $entityManager->flush();

                    return [
                        'uri' => sprintf('/api/game/%d/settings', (int) $game->getGameId()),
                        'content' => null,
                    ];
                },
            ],
            [
                'endpoint' => 'GET /api/game/{id}',
                'scenario' => 'lobby_three_players_full_state',
                'comparisonKey' => 'lobby_three_players',
                'method' => Request::METHOD_GET,
                'builder' => function (EntityManagerInterface $entityManager, User $benchmarkUser, int $run): array {
                    $game = $this->createLobbyGameScenario(
                        $entityManager,
                        $benchmarkUser,
                        sprintf('be201-lobby-full-%d', $run),
                        3,
                    );
                    $entityManager->flush();

                    return [
                        'uri' => sprintf('/api/game/%d', (int) $game->getGameId()),
                        'content' => null,
                    ];
                },
            ],
            [
                'endpoint' => 'GET /api/game/{id}/settings',
                'scenario' => 'started_medium',
                'comparisonKey' => 'started_medium',
                'method' => Request::METHOD_GET,
                'builder' => function (EntityManagerInterface $entityManager, User $benchmarkUser, int $run): array {
                    $game = $this->createStartedGameScenario(
                        $entityManager,
                        $benchmarkUser,
                        sprintf('be201-medium-%d', $run),
                        3,
                        5,
                        [2, 1, 0],
                    );
                    $entityManager->flush();

                    return [
                        'uri' => sprintf('/api/game/%d/settings', (int) $game->getGameId()),
                        'content' => null,
                    ];
                },
            ],
            [
                'endpoint' => 'GET /api/game/{id}',
                'scenario' => 'started_medium_full_state',
                'comparisonKey' => 'started_medium',
                'method' => Request::METHOD_GET,
                'builder' => function (EntityManagerInterface $entityManager, User $benchmarkUser, int $run): array {
                    $game = $this->createStartedGameScenario(
                        $entityManager,
                        $benchmarkUser,
                        sprintf('be201-medium-full-%d', $run),
                        3,
                        5,
                        [2, 1, 0],
                    );
                    $entityManager->flush();

                    return [
                        'uri' => sprintf('/api/game/%d', (int) $game->getGameId()),
                        'content' => null,
                    ];
                },
            ],
            [
                'endpoint' => 'GET /api/game/{id}/settings',
                'scenario' => 'started_long_history',
                'comparisonKey' => 'started_long_history',
                'method' => Request::METHOD_GET,
                'builder' => function (EntityManagerInterface $entityManager, User $benchmarkUser, int $run): array {
                    $game = $this->createStartedGameScenario(
                        $entityManager,
                        $benchmarkUser,
                        sprintf('be201-long-%d', $run),
                        4,
                        18,
                        [2, 2, 1, 0],
                    );
                    $entityManager->flush();

                    return [
                        'uri' => sprintf('/api/game/%d/settings', (int) $game->getGameId()),
                        'content' => null,
                    ];
                },
            ],
            [
                'endpoint' => 'GET /api/game/{id}',
                'scenario' => 'started_long_history_full_state',
                'comparisonKey' => 'started_long_history',
                'method' => Request::METHOD_GET,
                'builder' => function (EntityManagerInterface $entityManager, User $benchmarkUser, int $run): array {
                    $game = $this->createStartedGameScenario(
                        $entityManager,
                        $benchmarkUser,
                        sprintf('be201-long-full-%d', $run),
                        4,
                        18,
                        [2, 2, 1, 0],
                    );
                    $entityManager->flush();

                    return [
                        'uri' => sprintf('/api/game/%d', (int) $game->getGameId()),
                        'content' => null,
                    ];
                },
            ],
        ];

        $rows = [];
        foreach ($definitions as $definition) {
            $measurements = [];
            for ($run = 1; $run <= self::MEASUREMENT_RUNS; $run++) {
                $request = $definition['builder']($entityManager, $benchmarkUser, $run);
                $entityManager->flush();

                $measurements[] = $this->measureRequest(
                    $benchmarkUser,
                    $definition['method'],
                    $request['uri'],
                    $request['content'],
                );
            }

            $rows[] = $this->summarizeMeasurements(
                $definition['endpoint'],
                $definition['scenario'],
                $definition['comparisonKey'],
                $measurements,
            );
        }

        $report = $this->buildMarkdownReport($rows, $scenarioNotes);
        $outputPath = dirname(__DIR__, 2).self::OUTPUT_FILE;
        $outputDirectory = dirname($outputPath);
        if (!is_dir($outputDirectory)) {
            mkdir($outputDirectory, 0775, true);
        }

        file_put_contents($outputPath, $report);

        self::assertFileExists($outputPath);
        self::assertNotSame('', trim($report));
    }

    /**
     * @param array<int, array{sqlCount:int, latencyMs:float, sqlTimeMs:float, appDurationMs:float, payloadBytes:int}> $measurements
     *
     * @return array{endpoint:string, scenario:string, comparisonKey:string, sqlCount:int, latencyMedianMs:float, latencyRange:string, sqlTimeMedianMs:float, appDurationMedianMs:float, payloadBytes:int}
     */
    private function summarizeMeasurements(string $endpoint, string $scenario, string $comparisonKey, array $measurements): array
    {
        $sqlCounts = array_values(array_map(static fn(array $measurement): int => $measurement['sqlCount'], $measurements));
        sort($sqlCounts);
        self::assertCount(1, array_unique($sqlCounts), sprintf('SQL count for %s [%s] should be stable across runs.', $endpoint, $scenario));

        $payloadBytes = array_values(array_map(static fn(array $measurement): int => $measurement['payloadBytes'], $measurements));
        sort($payloadBytes);

        $latencies = array_values(array_map(static fn(array $measurement): float => $measurement['latencyMs'], $measurements));
        sort($latencies);
        $sqlTimes = array_values(array_map(static fn(array $measurement): float => $measurement['sqlTimeMs'], $measurements));
        sort($sqlTimes);
        $appDurations = array_values(array_map(static fn(array $measurement): float => $measurement['appDurationMs'], $measurements));
        sort($appDurations);

        return [
            'endpoint' => $endpoint,
            'scenario' => $scenario,
            'comparisonKey' => $comparisonKey,
            'sqlCount' => $sqlCounts[0],
            'latencyMedianMs' => $this->median($latencies),
            'latencyRange' => sprintf('%.2f–%.2f', min($latencies), max($latencies)),
            'sqlTimeMedianMs' => $this->median($sqlTimes),
            'appDurationMedianMs' => $this->median($appDurations),
            'payloadBytes' => (int) round($this->median(array_map(static fn(int $bytes): float => (float) $bytes, $payloadBytes))),
        ];
    }

    /**
     * @return array{sqlCount:int, latencyMs:float, sqlTimeMs:float, appDurationMs:float, payloadBytes:int}
     */
    private function measureRequest(User $user, string $method, string $uri, ?string $content): array
    {
        static::ensureKernelShutdown();
        $client = static::createClient();
        $client->loginUser($user);
        $client->enableProfiler();

        $start = hrtime(true);
        $client->request(
            $method,
            $uri,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $content ?? '',
        );
        $latencyMs = (hrtime(true) - $start) / 1_000_000;

        $response = $client->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), sprintf('Unexpected status for %s %s: %s', $method, $uri, $response->getContent()));

        $profile = $client->getProfile();
        self::assertNotFalse($profile, sprintf('Profiler is missing for %s %s.', $method, $uri));
        self::assertNotNull($profile, sprintf('Profiler is null for %s %s.', $method, $uri));

        /** @var object $dbCollector */
        $dbCollector = $profile->getCollector('db');
        /** @var object $timeCollector */
        $timeCollector = $profile->getCollector('time');

        return [
            'sqlCount' => $dbCollector->getQueryCount(),
            'latencyMs' => round($latencyMs, 2),
            'sqlTimeMs' => round($dbCollector->getTime(), 2),
            'appDurationMs' => round($timeCollector->getDuration(), 2),
            'payloadBytes' => strlen((string) $response->getContent()),
        ];
    }

    private function createLobbyGameScenario(EntityManagerInterface $entityManager, User $benchmarkUser, string $name, int $playerCount): Game
    {
        $game = $this->createGame($entityManager, GameStatus::Lobby);
        $players = $this->createPlayers($entityManager, $benchmarkUser, $name, $playerCount);
        foreach ($players as $position => $player) {
            $gamePlayer = (new GamePlayers())
                ->setGame($game)
                ->setPlayer($player)
                ->setPosition($position + 1)
                ->setScore($game->getStartScore())
                ->setIsWinner(false);

            $game->addGamePlayer($gamePlayer);
            $entityManager->persist($gamePlayer);
        }

        return $game;
    }

    /**
     * @param list<int> $currentRoundThrowCounts
     */
    private function createStartedGameScenario(EntityManagerInterface $entityManager, User $benchmarkUser, string $name, int $playerCount, int $completedRounds, array $currentRoundThrowCounts): Game
    {
        $game = $this->createGame($entityManager, GameStatus::Started);
        $players = $this->createPlayers($entityManager, $benchmarkUser, $name, $playerCount);
        $gamePlayers = [];
        foreach ($players as $position => $player) {
            $gamePlayer = (new GamePlayers())
                ->setGame($game)
                ->setPlayer($player)
                ->setPosition($position + 1)
                ->setScore($game->getStartScore())
                ->setIsWinner(false);

            $game->addGamePlayer($gamePlayer);
            $entityManager->persist($gamePlayer);
            $gamePlayers[] = $gamePlayer;
        }

        $currentRoundNumber = max(1, $completedRounds + 1);
        $game->setRound($currentRoundNumber);

        for ($roundNumber = 1; $roundNumber <= $completedRounds; $roundNumber++) {
            $this->createRound(
                $entityManager,
                $game,
                $gamePlayers,
                $roundNumber,
                array_fill(0, $playerCount, 3),
                true,
            );
        }

        $this->createRound(
            $entityManager,
            $game,
            $gamePlayers,
            $currentRoundNumber,
            $currentRoundThrowCounts,
            false,
        );

        return $game;
    }

    private function createGame(EntityManagerInterface $entityManager, GameStatus $status): Game
    {
        $game = (new Game())
            ->setDate(new \DateTime())
            ->setStartScore(501)
            ->setDoubleOut(false)
            ->setTripleOut(false)
            ->setStatus($status);

        $entityManager->persist($game);

        return $game;
    }

    /**
     * @param list<GamePlayers> $gamePlayers
     * @param list<int>         $throwCounts
     */
    private function createRound(EntityManagerInterface $entityManager, Game $game, array $gamePlayers, int $roundNumber, array $throwCounts, bool $finished): void
    {
        $round = (new Round())
            ->setGame($game)
            ->setRoundNumber($roundNumber)
            ->setStartedAt(new \DateTimeImmutable(sprintf('+%d minutes', $roundNumber)));

        if ($finished) {
            $round->setFinishedAt(new \DateTimeImmutable(sprintf('+%d minutes +50 seconds', $roundNumber)));
        }

        $game->addRound($round);
        $entityManager->persist($round);

        foreach ($gamePlayers as $playerIndex => $gamePlayer) {
            $throwCount = $throwCounts[$playerIndex] ?? 0;
            for ($throwNumber = 1; $throwNumber <= $throwCount; $throwNumber++) {
                $value = $this->resolveThrowValue($roundNumber, $playerIndex, $throwNumber);
                $currentScore = $gamePlayer->getScore() ?? $game->getStartScore();
                $newScore = $currentScore - $value;
                $gamePlayer->setScore($newScore);

                $throw = (new RoundThrows())
                    ->setGame($game)
                    ->setRound($round)
                    ->setPlayer($gamePlayer->getPlayer())
                    ->setThrowNumber($throwNumber)
                    ->setValue($value)
                    ->setIsBust(false)
                    ->setIsDouble(false)
                    ->setIsTriple(false)
                    ->setScore($newScore)
                    ->setTimestamp(new \DateTimeImmutable(sprintf('+%d minutes +%d seconds', $roundNumber, (($playerIndex + 1) * 10) + $throwNumber)));

                $round->addRoundThrow($throw);
                $entityManager->persist($throw);
            }
        }
    }

    private function resolveThrowValue(int $roundNumber, int $playerIndex, int $throwNumber): int
    {
        return 4 + (($roundNumber + $playerIndex + $throwNumber) % 3);
    }

    /**
     * @param list<string> $roles
     *
     * @return list<User>
     */
    private function createPlayers(EntityManagerInterface $entityManager, User $benchmarkUser, string $name, int $playerCount, array $roles = ['ROLE_PLAYER']): array
    {
        $benchmarkUserId = $benchmarkUser->getId();
        self::assertNotNull($benchmarkUserId);

        $managedBenchmarkUser = $entityManager->find(User::class, $benchmarkUserId);
        self::assertInstanceOf(User::class, $managedBenchmarkUser);

        $players = [$managedBenchmarkUser];
        for ($index = 1; $index < $playerCount; $index++) {
            $players[] = $this->createUser($entityManager, sprintf('%s-player-%d', $name, $index + 1), $roles);
        }

        return $players;
    }

    /**
     * @param list<string> $roles
     */
    private function createUser(EntityManagerInterface $entityManager, string $prefix, array $roles): User
    {
        $suffix = bin2hex(random_bytes(4));
        $username = substr(preg_replace('/[^a-z0-9_]/', '_', strtolower(sprintf('%s_%s', $prefix, $suffix))) ?? 'benchmark_user', 0, 30);
        $user = (new User())
            ->setEmail(sprintf('%s@benchmark.test', $suffix))
            ->setUsername($username)
            ->setPassword('unused')
            ->setRoles($roles);

        $entityManager->persist($user);

        return $user;
    }

    /**
     * @param list<float> $values
     */
    private function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);
        if (1 === ($count % 2)) {
            return round($values[$middle], 2);
        }

        return round(($values[$middle - 1] + $values[$middle]) / 2, 2);
    }

    /**
     * @param list<array{endpoint:string, scenario:string, comparisonKey:string, sqlCount:int, latencyMedianMs:float, latencyRange:string, sqlTimeMedianMs:float, appDurationMedianMs:float, payloadBytes:int}> $rows
     * @param array<string, string>                                                                                                                                                                             $scenarioNotes
     */
    private function buildMarkdownReport(array $rows, array $scenarioNotes): string
    {
        $lines = [
            '# BE-201 baseline for lightweight game settings endpoint',
            '',
            sprintf('- Generated at: %s UTC', gmdate('Y-m-d H:i:s')),
            '- Change scope: introduced `GET /api/game/{id}/settings` as a lightweight alternative to the full game state endpoint.',
            '- Environment: PHPUnit functional test (`APP_ENV=test`) with MySQL in Docker and Symfony profiler enabled.',
            sprintf('- Samples per row: %d identical runs; latency is wall-clock median measured around the in-process HTTP request.', self::MEASUREMENT_RUNS),
            '- Comparison model: each scenario is measured once for `GET /api/game/{id}/settings` and once for `GET /api/game/{id}` under the same fixture shape.',
            '',
            '## Current table',
            '',
            '| Endpoint | Scenario | SQL count | Payload bytes | Latency median (ms) | Latency range (ms) | SQL time median (ms) | App duration median (ms) |',
            '| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: |',
        ];

        foreach ($rows as $row) {
            $lines[] = sprintf(
                '| `%s` | `%s` | %d | %d | %.2f | %s | %.2f | %.2f |',
                $row['endpoint'],
                $row['scenario'],
                $row['sqlCount'],
                $row['payloadBytes'],
                $row['latencyMedianMs'],
                $row['latencyRange'],
                $row['sqlTimeMedianMs'],
                $row['appDurationMedianMs'],
            );
        }

        $lines[] = '';
        $lines[] = '## Delta vs full game state';
        $lines[] = '';
        $lines[] = '| Scenario | Full-state SQL | Settings SQL | SQL delta | Full-state payload bytes | Settings payload bytes | Payload reduction |';
        $lines[] = '| --- | ---: | ---: | ---: | ---: | ---: | ---: |';

        foreach ($scenarioNotes as $scenario => $note) {
            $settingsRow = $this->findRow($rows, 'GET /api/game/{id}/settings', $scenario);
            $fullStateRow = $this->findRow($rows, 'GET /api/game/{id}', $scenario.'_full_state');
            $payloadReduction = 100 - (($settingsRow['payloadBytes'] / $fullStateRow['payloadBytes']) * 100);

            $lines[] = sprintf(
                '| `%s` | %d | %d | %d | %d | %d | %.1f%% |',
                $scenario,
                $fullStateRow['sqlCount'],
                $settingsRow['sqlCount'],
                $settingsRow['sqlCount'] - $fullStateRow['sqlCount'],
                $fullStateRow['payloadBytes'],
                $settingsRow['payloadBytes'],
                $payloadReduction,
            );
        }

        $lines[] = '';
        $lines[] = '## Scenarios';
        $lines[] = '';
        foreach ($scenarioNotes as $scenario => $note) {
            $lines[] = sprintf('- `%s`: %s', $scenario, $note);
        }

        $lines[] = '';
        $lines[] = '## Notes';
        $lines[] = '';
        $lines[] = '- The lightweight endpoint avoids `GameService::createGameDto()` and returns only settings-focused data.';
        $lines[] = '- Payload size is measured as raw response body bytes from the functional response content.';
        $lines[] = '- SQL count should remain flat across started scenarios because the lightweight endpoint reads only the game plus access-related data, not throw history.';
        $lines[] = '';
        $lines[] = '## How to rerun';
        $lines[] = '';
        $lines[] = "- `docker compose exec -T php sh -lc 'cd /var/www/html && php bin/console doctrine:schema:drop --env=test --full-database --force || true && php bin/console doctrine:database:create --env=test --if-not-exists && php bin/console doctrine:schema:create --env=test && XDEBUG_MODE=coverage vendor/bin/phpunit --filter GameSettingsReadBaselineTest --testdox'`";
        $lines[] = '';
        $lines[] = 'The generated runtime report is also written to `app/var/benchmarks/be-201-game-settings-baseline.md`.';

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    /**
     * @param list<array{endpoint:string, scenario:string, comparisonKey:string, sqlCount:int, latencyMedianMs:float, latencyRange:string, sqlTimeMedianMs:float, appDurationMedianMs:float, payloadBytes:int}> $rows
     *
     * @return array{endpoint:string, scenario:string, comparisonKey:string, sqlCount:int, latencyMedianMs:float, latencyRange:string, sqlTimeMedianMs:float, appDurationMedianMs:float, payloadBytes:int}
     */
    private function findRow(array $rows, string $endpoint, string $scenario): array
    {
        foreach ($rows as $row) {
            if ($row['endpoint'] === $endpoint && $row['scenario'] === $scenario) {
                return $row;
            }
        }

        self::fail(sprintf('Missing benchmark row for %s [%s].', $endpoint, $scenario));
    }
}
