<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Benchmark;

use App\Enum\GameStatus;
use App\Enum\OutRule;
use App\Tests\Support\ScratchDatabase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * GET /api/player/profile through a real HTTP server under memory_limit = 64M (#259).
 *
 * Seeds a scratch database, starts PHP's built-in server in the prod environment and logs in
 * through POST /api/login. A prepended script records each request's peak memory. The report
 * goes to var/benchmarks; docs/benchmarks/BE-259-player-profile-memory.md keeps the results.
 */
#[CoversNothing]
#[Group('benchmark')]
final class PlayerProfileMemoryBaselineTest extends KernelTestCase
{
    private const array THROW_COUNTS = [30_000, 60_000, 240_000];
    private const array OPCACHE_MODES = ['on' => 1, 'off' => 0];
    private const int DARTS_PER_GAME = 60;
    private const int SAMPLES = 3;
    private const string PROFILE_PATH = '/api/player/profile';
    private const string PASSWORD = 'benchmark-password';
    private const string OUTPUT_FILE = '/var/benchmarks/be-259-player-profile-memory.md';

    /** One dart of the repeating pattern: value, double, triple. */
    private const array DART_PATTERN = [[20, 0, 0], [5, 0, 0], [1, 0, 0], [0, 0, 0], [12, 1, 0], [9, 0, 1]];

    /** Segment 20 is the most hit one: ten darts of the pattern and the checkout on double 20. */
    private const int SEGMENT_20_HITS_PER_GAME = 11;
    private const float SEGMENT_20_SHARE = 18.3;

    private ?string $workDir = null;

    /** @var resource|null */
    private $server = null;

    protected function tearDown(): void
    {
        $this->stopServer();
        if (null !== $this->workDir) {
            $this->emptyWorkDir();
            rmdir($this->workDir);
        }
        parent::tearDown();
    }

    public function testProfileStaysUnderTheMemoryLimitThroughTheRealEndpoint(): void
    {
        $kernel = self::bootKernel();
        $projectDir = $kernel->getProjectDir();
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $scratch = new ScratchDatabase($connection, 'profile_memory', $projectDir);
        $rows = [];

        try {
            $scratch->migrate();
            $seedConnection = $scratch->connect();
            $emails = [];
            foreach (self::THROW_COUNTS as $index => $throwCount) {
                $emails[$throwCount] = $this->seedPlayer($seedConnection, $index + 1, $throwCount);
            }

            $env = $this->serverEnvironment($connection, $scratch->name);
            $this->clearProdCache($projectDir, $env);

            foreach (self::OPCACHE_MODES as $label => $enabled) {
                foreach ($emails as $throwCount => $email) {
                    // A server of its own for each row: exhausting the memory can kill the process.
                    $origin = $this->startServer($projectDir, $env, $enabled);
                    $rows[] = ['throws' => $throwCount, 'opcache' => $label] + $this->measure($origin, $email);
                    $this->stopServer();
                }
            }
        } finally {
            $scratch->drop();
        }

        $outputPath = $projectDir.self::OUTPUT_FILE;
        if (!is_dir(dirname($outputPath))) {
            mkdir(dirname($outputPath), 0775, true);
        }
        file_put_contents($outputPath, $this->buildReport($rows));

        foreach ($rows as $row) {
            $context = sprintf('%d throws, OPcache %s, see %s', $row['throws'], $row['opcache'], $outputPath);
            self::assertSame(200, $row['status'], $context);
            self::assertSame(
                [
                    'segment' => '20',
                    'hits' => intdiv($row['throws'], self::DARTS_PER_GAME) * self::SEGMENT_20_HITS_PER_GAME,
                    'share' => self::SEGMENT_20_SHARE,
                ],
                $row['mostHitSegment'],
                $context,
            );
        }
    }

    /**
     * Inserts a player with one opponent seat per game and the player's throws in plain SQL, with
     * ids that repeat on every run, so that the response bodies of two runs can be compared.
     *
     * @return string the player's email
     */
    private function seedPlayer(Connection $connection, int $playerNumber, int $throwCount): string
    {
        $playerId = 1000 + $playerNumber * 2;
        $opponentId = $playerId + 1;
        $email = sprintf('profile-%d@benchmark.test', $playerNumber);
        $hash = password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]);
        $insertUser = 'INSERT INTO `user` (id, email, roles, password, username, display_name) VALUES (?, ?, ?, ?, ?, ?)';
        $connection->executeStatement($insertUser, [$playerId, $email, '["ROLE_PLAYER"]', $hash, 'profile'.$playerNumber, '']);
        $connection->executeStatement($insertUser, [$opponentId, $email.'.opponent', '["ROLE_PLAYER"]', $hash, 'opponent'.$playerNumber, '']);

        $connection->transactional(function (Connection $connection) use ($playerNumber, $playerId, $opponentId, $throwCount): void {
            $gameCount = (int) ceil($throwCount / self::DARTS_PER_GAME);
            $firstGameId = $playerNumber * 1_000_000;
            $values = array_map(static fn(int $dart): int => self::patternDart($dart)[0], range(0, self::DARTS_PER_GAME - 2));
            $startScore = array_sum($values) + 40;
            $throwRows = [];
            for ($game = 0; $game < $gameCount; $game++) {
                $gameId = $firstGameId + $game;
                $outRule = (0 === $game % 2 ? OutRule::Double : OutRule::Single)->value;
                $date = gmdate('Y-m-d H:i:s', 1_700_000_000 + $game * 3600);
                $connection->executeStatement(
                    'INSERT INTO game (game_id, date, round, start_score, status, finished_at, out_rule) VALUES (?, ?, 20, ?, ?, ?, ?)',
                    [$gameId, substr($date, 0, 10), $startScore, GameStatus::Finished->value, $date, $outRule],
                );
                $placement = 0 === $game % 3 ? 1 : 2;
                $connection->executeStatement(
                    'INSERT INTO game_players (game_id, player_id, position, score, is_winner, display_name_snapshot, placement) VALUES (?, ?, 1, 0, ?, ?, ?), (?, ?, 2, 50, ?, ?, ?)',
                    [$gameId, $playerId, 1 === $placement ? 1 : 0, 'player', $placement, $gameId, $opponentId, 1 === $placement ? 0 : 1, 'opponent', 3 - $placement],
                );
                $remaining = $startScore;
                $roundId = $gameId * 100;
                for ($dart = 0; $dart < self::DARTS_PER_GAME; $dart++) {
                    if (0 === $dart % 3) {
                        $roundId++;
                        $connection->executeStatement(
                            'INSERT INTO round (round_id, game_id, round_number, started_at, finished_at) VALUES (?, ?, ?, ?, ?)',
                            [$roundId, $gameId, intdiv($dart, 3) + 1, $date, $date],
                        );
                    }
                    [$value, $isDouble, $isTriple] = self::DARTS_PER_GAME - 1 === $dart ? [40, 1, 0] : self::patternDart($dart);
                    $remaining -= $value;
                    $throwRows[] = [$gameId, $roundId, $playerId, $dart % 3 + 1, $value, $isDouble, $isTriple, $remaining, $date];
                    if (count($throwRows) >= 1000) {
                        $this->insertThrows($connection, $throwRows);
                        $throwRows = [];
                    }
                }
            }
            $this->insertThrows($connection, $throwRows);
        });

        return $email;
    }

    /**
     * @return array{int, int, int} value, double, triple
     */
    private static function patternDart(int $dart): array
    {
        return self::DART_PATTERN[$dart % count(self::DART_PATTERN)];
    }

    /**
     * @param list<list<int|string>> $rows
     */
    private function insertThrows(Connection $connection, array $rows): void
    {
        if ([] !== $rows) {
            $connection->executeStatement(
                'INSERT INTO round_throws (game_id, round_id, player_id, throw_number, value, is_double, is_triple, score, timestamp) VALUES '.implode(',', array_fill(0, count($rows), '(?, ?, ?, ?, ?, ?, ?, ?, ?)')),
                array_merge(...$rows),
            );
        }
    }

    /**
     * @return array<string, string>
     */
    private function serverEnvironment(Connection $connection, string $databaseName): array
    {
        $params = $connection->getParams();

        return [
            ...getenv(),
            'APP_ENV' => 'prod',
            'APP_DEBUG' => '0',
            'SHELL_VERBOSITY' => '0',
            'DATABASE_URL' => sprintf(
                'mysql://%s:%s@%s:%d/%s?serverVersion=8.0&charset=utf8mb4',
                rawurlencode((string) ($params['user'] ?? '')),
                rawurlencode((string) ($params['password'] ?? '')),
                (string) ($params['host'] ?? ''),
                (int) ($params['port'] ?? 3306),
                $databaseName,
            ),
        ];
    }

    /**
     * @param array<string, string> $env
     */
    private function clearProdCache(string $projectDir, array $env): void
    {
        $process = proc_open(
            [PHP_BINARY, '-d', 'memory_limit=-1', 'bin/console', 'cache:clear', '--no-interaction', '--quiet'],
            [],
            $pipes,
            $projectDir,
            $env,
        );
        self::assertIsResource($process);
        self::assertSame(0, proc_close($process), 'cache:clear failed for the prod environment.');
    }

    /**
     * @param array<string, string> $env
     *
     * @return string the server's origin
     */
    private function startServer(string $projectDir, array $env, int $opcache): string
    {
        $this->workDir ??= $this->createWorkDir();
        $this->emptyWorkDir();
        $peakFile = $this->workDir.'/peak.log';
        $prepend = $this->workDir.'/prepend.php';
        file_put_contents($prepend, sprintf(
            '<?php register_shutdown_function(static fn() => file_put_contents(%s, ($_SERVER["REQUEST_URI"] ?? "")." ".memory_get_peak_usage(true)."\n", FILE_APPEND));',
            var_export($peakFile, true),
        ));

        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $address = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        $this->server = proc_open(
            [
                PHP_BINARY,
                '-d', 'memory_limit=64M',
                '-d', 'xdebug.mode=off',
                '-d', 'log_errors=1',
                '-d', 'opcache.enable='.$opcache,
                '-d', 'opcache.memory_consumption=64',
                '-d', 'opcache.interned_strings_buffer=8',
                '-d', 'opcache.max_accelerated_files=20000',
                '-d', 'realpath_cache_size=4096K',
                '-d', 'auto_prepend_file='.$prepend,
                '-S', $address,
                '-t', 'public',
            ],
            [1 => ['file', $this->workDir.'/server.log', 'a'], 2 => ['file', $this->workDir.'/server.log', 'a']],
            $pipes,
            $projectDir,
            $env,
        );
        self::assertIsResource($this->server);

        $origin = 'http://'.$address;
        for ($attempt = 0; $attempt < 100; $attempt++) {
            if (200 === $this->request($origin, 'GET', '/api/health')['status']) {
                return $origin;
            }
            usleep(100_000);
        }
        self::fail('The server did not answer /api/health: '.$this->serverLog());
    }

    private function stopServer(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        $this->server = null;
    }

    private function createWorkDir(): string
    {
        $directory = sys_get_temp_dir().'/be259-'.bin2hex(random_bytes(4));
        mkdir($directory);

        return $directory;
    }

    private function emptyWorkDir(): void
    {
        array_map('unlink', glob($this->workDir.'/*') ?: []);
    }

    private function serverLog(): string
    {
        return (string) file_get_contents($this->workDir.'/server.log');
    }

    /**
     * One warm-up request, then SAMPLES measured ones.
     *
     * @return array{status:int, peakMb:float|null, medianMs:float, bytes:int, sha256:string, error:string, mostHitSegment:mixed}
     */
    private function measure(string $origin, string $email): array
    {
        $login = $this->request($origin, 'POST', '/api/login', http_build_query([
            '_username' => $email,
            '_password' => self::PASSWORD,
            '_csrf_token' => 'csrf-token',
        ]));
        self::assertNotSame([], $login['cookies'], 'Login set no cookie: '.$login['body']);
        $cookie = implode('; ', $login['cookies']);

        $this->request($origin, 'GET', self::PROFILE_PATH, null, $cookie);
        $times = [];
        $peaks = [];
        $response = ['status' => 0, 'body' => '', 'cookies' => []];
        for ($sample = 0; $sample < self::SAMPLES; $sample++) {
            $start = hrtime(true);
            $response = $this->request($origin, 'GET', self::PROFILE_PATH, null, $cookie);
            $times[] = (hrtime(true) - $start) / 1_000_000;
            $peaks[] = 200 === $response['status'] ? $this->lastPeak() : 0;
        }
        sort($times);
        $log = $this->serverLog();

        return [
            'status' => $response['status'],
            'peakMb' => 200 === $response['status'] ? max($peaks) / 1_048_576 : null,
            'medianMs' => $times[intdiv(count($times), 2)],
            'bytes' => strlen($response['body']),
            'sha256' => hash('sha256', $response['body']),
            'error' => 1 === preg_match('/(?:Allowed|Hard) memory[^\n"]*?exhausted/', $log, $match) ? $match[0] : '',
            'mostHitSegment' => json_decode($response['body'], true)['stats']['mostHitSegment'] ?? null,
        ];
    }

    /**
     * The peak of the profile request that just ended. Its shutdown function runs after the
     * response is sent, so this waits for the record to appear.
     */
    private function lastPeak(): int
    {
        $file = (string) $this->workDir.'/peak.log';
        for ($attempt = 0; $attempt < 100; $attempt++) {
            clearstatcache();
            $lines = is_file($file) ? (array) file($file, FILE_IGNORE_NEW_LINES) : [];
            $last = (string) end($lines);
            if (str_starts_with($last, self::PROFILE_PATH.' ')) {
                unlink($file);

                return (int) substr($last, strlen(self::PROFILE_PATH) + 1);
            }
            usleep(20_000);
        }
        self::fail('No peak memory was recorded for the profile request: '.substr($this->serverLog(), -1500));
    }

    /**
     * @return array{status:int, body:string, cookies:list<string>}
     */
    private function request(string $origin, string $method, string $path, ?string $form = null, ?string $cookie = null): array
    {
        $headers = ['Origin: '.$origin, 'Sec-Fetch-Site: same-origin', 'Accept: application/json', 'Connection: close'];
        if (null !== $form) {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        if (null !== $cookie) {
            $headers[] = 'Cookie: '.$cookie;
        }
        $body = @file_get_contents($origin.$path, false, stream_context_create(['http' => [
            'method' => $method,
            'header' => $headers,
            'content' => $form ?? '',
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => 60,
        ]]));
        $responseHeaders = http_get_last_response_headers() ?? [];
        http_clear_last_response_headers();

        $cookies = [];
        foreach ($responseHeaders as $header) {
            if (1 === preg_match('/^Set-Cookie:\s*([^;]+)/i', $header, $match)) {
                $cookies[] = $match[1];
            }
        }

        return [
            'status' => 1 === preg_match('/^HTTP\/\S+ (\d+)/', $responseHeaders[0] ?? '', $match) ? (int) $match[1] : 0,
            'body' => false === $body ? '' : $body,
            'cookies' => $cookies,
        ];
    }

    /**
     * @param list<array{throws:int, opcache:string, status:int, peakMb:float|null, medianMs:float, bytes:int, sha256:string, error:string, mostHitSegment:mixed}> $rows
     */
    private function buildReport(array $rows): string
    {
        $lines = [
            sprintf('Generated at: %s UTC', gmdate('Y-m-d H:i:s')),
            '',
            '| Throws | OPcache | Status | Peak memory (MiB) | Median time (ms) | Body bytes | Body SHA-256 | Memory error |',
            '| ---: | --- | ---: | ---: | ---: | ---: | --- | --- |',
        ];
        foreach ($rows as $row) {
            $lines[] = sprintf(
                '| %d | %s | %d | %s | %.0f | %d | `%s` | %s |',
                $row['throws'],
                $row['opcache'],
                $row['status'],
                null === $row['peakMb'] ? 'n/a' : sprintf('%.1f', $row['peakMb']),
                $row['medianMs'],
                $row['bytes'],
                substr($row['sha256'], 0, 16),
                $row['error'],
            );
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }
}
