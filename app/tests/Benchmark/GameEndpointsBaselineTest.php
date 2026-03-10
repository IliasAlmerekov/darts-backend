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
final class GameEndpointsBaselineTest extends WebTestCase
{
    private const int MEASUREMENT_RUNS = 3;

    private const string OUTPUT_FILE = '/var/benchmarks/be-101-game-endpoints-baseline.md';

    public function testCollectsBaselineMetricsForGameEndpoints(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $benchmarkUser = $this->createUser($entityManager, 'baseline-primary', ['ROLE_PLAYER']);
        $entityManager->flush();

        $scenarioNotes = [
            'small_started' => '2 players, started game, current round only, no persisted round history.',
            'medium_started' => '3 players, started game, 5 finished rounds plus partial current round.',
            'long_started_with_history' => '4 players, started game, 18 finished rounds plus partial current round.',
            'lobby_settings_update' => '3 players, lobby game used for PATCH settings baseline.',
            'started_undo_last_throw' => '4 players, started game with 12 finished rounds and a partially played current round used for DELETE throw baseline.',
        ];

        $definitions = [
            [
                'endpoint' => 'GET /api/game/{id}',
                'scenario' => 'small_started',
                'method' => Request::METHOD_GET,
                'builder' => function (EntityManagerInterface $entityManager, User $benchmarkUser, int $run): array {
                    $game = $this->createStartedGameScenario(
                        $entityManager,
                        $benchmarkUser,
                        sprintf('small-%d', $run),
                        2,
                        0,
                        [0, 0],
                    );
                    $entityManager->flush();

                    return [
                        'uri' => sprintf('/api/game/%d', (int) $game->getGameId()),
                        'content' => null,
                    ];
                },
            ],
            [
                'endpoint' => 'GET /api/game/{id}',
                'scenario' => 'medium_started',
                'method' => Request::METHOD_GET,
                'builder' => function (EntityManagerInterface $entityManager, User $benchmarkUser, int $run): array {
                    $game = $this->createStartedGameScenario(
                        $entityManager,
                        $benchmarkUser,
                        sprintf('medium-%d', $run),
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
                'endpoint' => 'GET /api/game/{id}',
                'scenario' => 'long_started_with_history',
                'method' => Request::METHOD_GET,
                'builder' => function (EntityManagerInterface $entityManager, User $benchmarkUser, int $run): array {
                    $game = $this->createStartedGameScenario(
                        $entityManager,
                        $benchmarkUser,
                        sprintf('long-%d', $run),
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
            [
                'endpoint' => 'PATCH /api/game/{id}/settings',
                'scenario' => 'lobby_settings_update',
                'method' => Request::METHOD_PATCH,
                'builder' => function (EntityManagerInterface $entityManager, User $benchmarkUser, int $run): array {
                    $game = $this->createLobbyGameScenario(
                        $entityManager,
                        $benchmarkUser,
                        sprintf('settings-%d', $run),
                        3,
                    );
                    $entityManager->flush();

                    return [
                        'uri' => sprintf('/api/game/%d/settings', (int) $game->getGameId()),
                        'content' => json_encode([
                            'startScore' => 501,
                            'doubleOut' => true,
                        ], JSON_THROW_ON_ERROR),
                    ];
                },
            ],
            [
                'endpoint' => 'DELETE /api/game/{id}/throw',
                'scenario' => 'started_undo_last_throw',
                'method' => Request::METHOD_DELETE,
                'builder' => function (EntityManagerInterface $entityManager, User $benchmarkUser, int $run): array {
                    $game = $this->createStartedGameScenario(
                        $entityManager,
                        $benchmarkUser,
                        sprintf('undo-%d', $run),
                        4,
                        12,
                        [3, 3, 2, 1],
                    );
                    $entityManager->flush();

                    return [
                        'uri' => sprintf('/api/game/%d/throw', (int) $game->getGameId()),
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
        foreach ($rows as $row) {
            self::assertGreaterThan(0, $row['sqlCount']);
            self::assertGreaterThan(0.0, $row['latencyMedianMs']);
        }
    }

    /**
     * @param array<int, array{sqlCount:int, latencyMs:float, sqlTimeMs:float, appDurationMs:float}> $measurements
     *
     * @return array{endpoint:string, scenario:string, sqlCount:int, latencyMedianMs:float, latencyRange:string, sqlTimeMedianMs:float, appDurationMedianMs:float}
     */
    private function summarizeMeasurements(string $endpoint, string $scenario, array $measurements): array
    {
        $sqlCounts = array_values(array_map(static fn(array $measurement): int => $measurement['sqlCount'], $measurements));
        sort($sqlCounts);
        self::assertCount(1, array_unique($sqlCounts), sprintf('SQL count for %s [%s] should be stable across runs.', $endpoint, $scenario));

        $latencies = array_values(array_map(static fn(array $measurement): float => $measurement['latencyMs'], $measurements));
        sort($latencies);
        $sqlTimes = array_values(array_map(static fn(array $measurement): float => $measurement['sqlTimeMs'], $measurements));
        sort($sqlTimes);
        $appDurations = array_values(array_map(static fn(array $measurement): float => $measurement['appDurationMs'], $measurements));
        sort($appDurations);

        return [
            'endpoint' => $endpoint,
            'scenario' => $scenario,
            'sqlCount' => $sqlCounts[0],
            'latencyMedianMs' => $this->median($latencies),
            'latencyRange' => sprintf('%.2f–%.2f', min($latencies), max($latencies)),
            'sqlTimeMedianMs' => $this->median($sqlTimes),
            'appDurationMedianMs' => $this->median($appDurations),
        ];
    }

    /**
     * @return array{sqlCount:int, latencyMs:float, sqlTimeMs:float, appDurationMs:float}
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
     * @param list<User>        $players
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
     * @param list<array{endpoint:string, scenario:string, sqlCount:int, latencyMedianMs:float, latencyRange:string, sqlTimeMedianMs:float, appDurationMedianMs:float}> $rows
     * @param array<string, string>                                                                                                                                   $scenarioNotes
     */
    private function buildMarkdownReport(array $rows, array $scenarioNotes): string
    {
        $lines = [
            '# BE-101 baseline for game endpoints',
            '',
            sprintf('- Generated at: %s UTC', gmdate('Y-m-d H:i:s')),
            '- Environment: PHPUnit functional test (`APP_ENV=test`) with MySQL in Docker and Symfony profiler enabled.',
            sprintf('- Samples per row: %d identical runs; latency is wall-clock median measured around the in-process HTTP request.', self::MEASUREMENT_RUNS),
            '',
            '## Baseline table',
            '',
            '| Endpoint | Scenario | SQL count | Latency median (ms) | Latency range (ms) | SQL time median (ms) | App duration median (ms) |',
            '| --- | --- | ---: | ---: | ---: | ---: | ---: |',
        ];

        foreach ($rows as $row) {
            $lines[] = sprintf(
                '| `%s` | `%s` | %d | %.2f | %s | %.2f | %.2f |',
                $row['endpoint'],
                $row['scenario'],
                $row['sqlCount'],
                $row['latencyMedianMs'],
                $row['latencyRange'],
                $row['sqlTimeMedianMs'],
                $row['appDurationMedianMs'],
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
        $lines[] = '- This baseline is intended for before/after comparison during endpoint optimization.';
        $lines[] = '- `GET /api/game/{id}` is measured on three representative scenarios: small, medium, and long-with-history.';
        $lines[] = '- `PATCH /api/game/{id}/settings` and `DELETE /api/game/{id}/throw` are captured separately because both also return the full game DTO.';

        return implode(PHP_EOL, $lines).PHP_EOL;
    }
}
