<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Game;
use App\Entity\GamePlayers;
use App\Entity\Invitation;
use App\Entity\Round;
use App\Entity\User;
use App\Enum\GameStatus;
use DateTime;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Checks every API route against every kind of caller on the real kernel.
 *
 * The policy comes from docs/adr/0001-single-tablet-admin.md: a few routes are public,
 * invitation processing is for players, and everything else under /api is for the admin
 * account on the shared tablet.
 *
 * @psalm-type MatrixEntry = array{route: string, method: string, access: string, parameters: array<string, string>, body: string, state: string, payload: array<string, mixed>, query: array<string, string>, expected: int|array<string, int>, knownIssue: string|null}
 */
final class ApiAccessMatrixTest extends WebTestCase
{
    private const string ACCESS_PUBLIC = 'public';
    private const string ACCESS_PLAYER = 'player';
    private const string ACCESS_ADMIN = 'admin';

    private const string PERSONA_ANONYMOUS = 'anonymous';
    private const string PERSONA_ROLE_USER_ONLY = 'role_user_only';
    private const string PERSONA_GUEST = 'guest';
    private const string PERSONA_PLAYER_OUTSIDER = 'player_outsider';
    private const string PERSONA_PLAYER_PARTICIPANT = 'player_participant';
    private const string PERSONA_ADMIN = 'admin';

    /** Routes without a method restriction accept any verb; the matrix sends GET to them. */
    private const string METHOD_ANY = 'ANY';

    /** Fixture keys that route parameters resolve to. */
    private const string FIXTURE_GAME = 'game';
    private const string FIXTURE_MISSING_GAME = 'missing_game';
    private const string FIXTURE_INVITATION = 'invitation';

    private const string BODY_JSON = 'json';
    private const string BODY_LOGIN_FORM = 'login_form';

    /** Game states the fixture can be built in. */
    private const string STATE_LOBBY = 'lobby';
    private const string STATE_STARTED = 'started';
    private const string STATE_FINISHED = 'finished';

    /** Placeholders in payloads and queries that resolve to fixture user ids. */
    private const string TOKEN_PARTICIPANT_ID = '@participantId';
    private const string TOKEN_OPPONENT_ID = '@opponentId';

    private const string LOGIN_PASSWORD = 'matrix-secret';

    /**
     * Route parameters map to fixture keys so the URIs point at real rows.
     *
     * Each entry also pins the exact status every allowed persona gets. Where a minimal
     * request honestly ends in a 4xx, the entry says why in a comment next to it.
     *
     * `room_stream` points at a missing game on purpose: for an allowed caller the
     * controller would otherwise open an endless SSE loop in the test client, and the
     * 404 it returns instead still proves the request passed the access rules.
     *
     * @return list<MatrixEntry>
     */
    private static function matrix(): array
    {
        $game = ['gameId' => self::FIXTURE_GAME];
        $room = ['id' => self::FIXTURE_GAME];
        $throw = ['playerId' => self::TOKEN_PARTICIPANT_ID, 'value' => 20];

        return [
            // Public
            self::entry('app_login', Request::METHOD_GET, self::ACCESS_PUBLIC, expected: Response::HTTP_OK),
            self::entry('app_login', Request::METHOD_POST, self::ACCESS_PUBLIC, body: self::BODY_LOGIN_FORM, expected: Response::HTTP_OK),
            self::entry('login_success', self::METHOD_ANY, self::ACCESS_PUBLIC, expected: Response::HTTP_OK),
            self::entry('app_logout', self::METHOD_ANY, self::ACCESS_PUBLIC, expected: Response::HTTP_FOUND),
            self::entry('app_register', Request::METHOD_POST, self::ACCESS_PUBLIC, payload: ['email' => 'matrix-register@test.dev', 'username' => 'matrix_register', 'plainPassword' => 'matrix-secret'], expected: Response::HTTP_CREATED),
            self::entry('app_csrf_tokens', Request::METHOD_GET, self::ACCESS_PUBLIC, expected: Response::HTTP_OK),
            self::entry('api_health', Request::METHOD_GET, self::ACCESS_PUBLIC, expected: Response::HTTP_OK),
            self::entry('join_invitation', self::METHOD_ANY, self::ACCESS_PUBLIC, ['uuid' => self::FIXTURE_INVITATION], expected: Response::HTTP_FOUND),

            // Players
            // The session holds no joined invitation, so the service answers 400 with a redirect to /start.
            self::entry('process_invitation', Request::METHOD_POST, self::ACCESS_PLAYER, expected: Response::HTTP_BAD_REQUEST),

            // Admin: invitations
            self::entry('create_invitation', Request::METHOD_POST, self::ACCESS_ADMIN, $room, expected: Response::HTTP_OK),

            // Admin: rooms
            self::entry('room_create', Request::METHOD_POST, self::ACCESS_ADMIN, expected: Response::HTTP_OK),
            self::entry('room_player_leave', Request::METHOD_DELETE, self::ACCESS_ADMIN, $room, query: ['playerId' => self::TOKEN_OPPONENT_ID], expected: Response::HTTP_OK),
            self::entry('room_player_guest_add', Request::METHOD_POST, self::ACCESS_ADMIN, $room, payload: ['username' => 'Guest Alex'], expected: Response::HTTP_OK),
            self::entry('room_update_player_positions', Request::METHOD_POST, self::ACCESS_ADMIN, $room, payload: ['positions' => [['playerId' => self::TOKEN_PARTICIPANT_ID, 'position' => 2], ['playerId' => self::TOKEN_OPPONENT_ID, 'position' => 1]]], expected: Response::HTTP_OK),
            // Missing game, see the method comment: the exception is the honest 404 here.
            self::entry('room_stream', Request::METHOD_GET, self::ACCESS_ADMIN, ['id' => self::FIXTURE_MISSING_GAME], expected: Response::HTTP_NOT_FOUND),
            self::entry('room_rematch', Request::METHOD_POST, self::ACCESS_ADMIN, $room, expected: Response::HTTP_CREATED),

            // Admin: game lifecycle
            self::entry('app_game_state', Request::METHOD_GET, self::ACCESS_ADMIN, $game, expected: Response::HTTP_OK),
            self::entry('app_game_start', Request::METHOD_POST, self::ACCESS_ADMIN, $game, expected: Response::HTTP_OK),
            // Returns 400 GAME_INVALID_PLAYER_COUNT for a finished two-player game until #73 is fixed.
            self::entry('app_game_rematch_start', Request::METHOD_POST, self::ACCESS_ADMIN, $game, state: self::STATE_FINISHED, expected: Response::HTTP_CREATED, knownIssue: '#73'),
            self::entry('app_game_settings_create', Request::METHOD_POST, self::ACCESS_ADMIN, payload: ['startScore' => 501], expected: Response::HTTP_CREATED),
            self::entry('app_game_settings', Request::METHOD_PATCH, self::ACCESS_ADMIN, $game, payload: ['startScore' => 501], expected: Response::HTTP_OK),
            self::entry('app_game_settings_read', Request::METHOD_GET, self::ACCESS_ADMIN, $game, expected: Response::HTTP_OK),
            self::entry('app_game_finish', Request::METHOD_POST, self::ACCESS_ADMIN, $game, expected: Response::HTTP_OK),
            self::entry('app_game_reopen', Request::METHOD_PATCH, self::ACCESS_ADMIN, $game, state: self::STATE_FINISHED, expected: Response::HTTP_OK),
            self::entry('app_game_finished', Request::METHOD_GET, self::ACCESS_ADMIN, $game, expected: Response::HTTP_OK),
            self::entry('app_games_finished', Request::METHOD_GET, self::ACCESS_ADMIN, $game, expected: Response::HTTP_OK),
            self::entry('app_game_abort', Request::METHOD_PATCH, self::ACCESS_ADMIN, $game, expected: Response::HTTP_OK),

            // Admin: throws
            self::entry('app_game_throw', Request::METHOD_POST, self::ACCESS_ADMIN, $game, state: self::STATE_STARTED, payload: $throw, expected: Response::HTTP_OK),
            self::entry('app_game_throw_delta', Request::METHOD_POST, self::ACCESS_ADMIN, $game, state: self::STATE_STARTED, payload: $throw, expected: Response::HTTP_OK),
            self::entry('app_game_throw_undo', Request::METHOD_DELETE, self::ACCESS_ADMIN, $game, state: self::STATE_STARTED, expected: Response::HTTP_OK),
            self::entry('app_game_throw_undo_delta', Request::METHOD_DELETE, self::ACCESS_ADMIN, $game, state: self::STATE_STARTED, expected: Response::HTTP_OK),

            // Admin: statistics
            self::entry('app_games_overview', Request::METHOD_GET, self::ACCESS_ADMIN, expected: Response::HTTP_OK),
            self::entry('app_games_details', Request::METHOD_GET, self::ACCESS_ADMIN, $game, expected: Response::HTTP_OK),
            self::entry('app_players_stats', Request::METHOD_GET, self::ACCESS_ADMIN, expected: Response::HTTP_OK),
        ];
    }

    /**
     * @return iterable<string, array{MatrixEntry, string}>
     */
    public static function accessCaseProvider(): iterable
    {
        $personas = [
            self::PERSONA_ANONYMOUS,
            self::PERSONA_ROLE_USER_ONLY,
            self::PERSONA_GUEST,
            self::PERSONA_PLAYER_OUTSIDER,
            self::PERSONA_PLAYER_PARTICIPANT,
            self::PERSONA_ADMIN,
        ];

        foreach (self::matrix() as $entry) {
            foreach ($personas as $persona) {
                yield sprintf('%s %s as %s', $entry['method'], $entry['route'], $persona) => [$entry, $persona];
            }
        }
    }

    /**
     * @param MatrixEntry $entry
     */
    #[DataProvider('accessCaseProvider')]
    public function testRouteAccessForPersona(array $entry, string $persona): void
    {
        $client = static::createClient();
        $fixtures = $this->createFixtures($entry['state']);

        if (self::PERSONA_ANONYMOUS !== $persona) {
            $client->loginUser($fixtures['personas'][$persona]);
        }

        $router = static::getContainer()->get(RouterInterface::class);
        $uri = $router->generate($entry['route'], $this->resolveParameters($entry['parameters'], $fixtures) + $this->resolveTokens($entry['query'], $fixtures));
        $httpMethod = self::METHOD_ANY === $entry['method'] ? Request::METHOD_GET : $entry['method'];

        $snapshotBefore = $this->snapshotDatabase();
        $this->sendRequest($client, $httpMethod, $uri, $entry['body'], $this->resolveTokens($entry['payload'], $fixtures), $fixtures['loginEmail']);

        if (self::isAllowed($entry['access'], $persona)) {
            $expected = is_int($entry['expected']) ? $entry['expected'] : $entry['expected'][$persona] ?? $entry['expected']['*'];
            if (null !== $entry['knownIssue']) {
                $actual = $client->getResponse()->getStatusCode();
                self::assertNotSame(
                    $expected,
                    $actual,
                    sprintf('%s %s now returns %d: %s looks fixed, drop knownIssue from this entry.', $httpMethod, $uri, $expected, $entry['knownIssue']),
                );
                self::markTestIncomplete(sprintf('%s %s returns %d instead of %d, see %s.', $httpMethod, $uri, $actual, $expected, $entry['knownIssue']));
            }

            self::assertResponseStatusCodeSame(
                $expected,
                sprintf('%s %s should return %d for %s.', $httpMethod, $uri, $expected, $persona),
            );

            return;
        }

        $expectedStatus = self::PERSONA_ANONYMOUS === $persona ? Response::HTTP_UNAUTHORIZED : Response::HTTP_FORBIDDEN;
        self::assertResponseStatusCodeSame(
            $expectedStatus,
            sprintf('%s %s should be denied for %s.', $httpMethod, $uri, $persona),
        );

        if (Request::METHOD_GET !== $httpMethod) {
            self::assertSame(
                $snapshotBefore,
                $this->snapshotDatabase(),
                sprintf('Denied %s %s changed the database for %s.', $httpMethod, $uri, $persona),
            );
        }
    }

    public function testEveryApiRouteAndMethodIsInTheMatrix(): void
    {
        $router = static::getContainer()->get(RouterInterface::class);

        $routed = [];
        foreach ($router->getRouteCollection()->all() as $name => $route) {
            // Some routes are declared without a leading slash, e.g. 'api/invite/process'.
            $path = '/'.ltrim($route->getPath(), '/');
            if (!str_starts_with($path, '/api/')) {
                continue;
            }

            $methods = $route->getMethods();
            foreach ([] === $methods ? [self::METHOD_ANY] : $methods as $method) {
                $routed[] = sprintf('%s %s', $method, $name);
            }
        }

        $covered = array_map(
            static fn (array $entry): string => sprintf('%s %s', $entry['method'], $entry['route']),
            self::matrix(),
        );

        self::assertNotEmpty($routed, 'The router returned no /api/ routes.');
        self::assertSame(array_values(array_unique($covered)), $covered, 'The matrix lists a route and method twice.');
        self::assertSame([], array_values(array_diff($routed, $covered)), 'These API routes are missing from the access matrix.');
        self::assertSame([], array_values(array_diff($covered, $routed)), 'These matrix entries match no API route.');
    }

    /**
     * @param array<string, string>   $parameters
     * @param array<string, mixed>    $payload
     * @param array<string, string>   $query
     * @param int|array<string, int> $expected   Exact status for every allowed persona, or per persona with a '*' fallback.
     * @param string|null            $knownIssue Issue that keeps an allowed cell from returning $expected; the cell is incomplete until the issue is fixed.
     *
     * @return MatrixEntry
     */
    private static function entry(
        string $route,
        string $method,
        string $access,
        array $parameters = [],
        string $body = self::BODY_JSON,
        string $state = self::STATE_LOBBY,
        array $payload = [],
        array $query = [],
        int|array $expected = Response::HTTP_OK,
        ?string $knownIssue = null,
    ): array {
        return ['route' => $route, 'method' => $method, 'access' => $access, 'parameters' => $parameters, 'body' => $body, 'state' => $state, 'payload' => $payload, 'query' => $query, 'expected' => $expected, 'knownIssue' => $knownIssue];
    }

    private static function isAllowed(string $access, string $persona): bool
    {
        return match ($access) {
            self::ACCESS_PUBLIC => true,
            self::ACCESS_PLAYER => in_array($persona, [self::PERSONA_PLAYER_OUTSIDER, self::PERSONA_PLAYER_PARTICIPANT], true),
            self::ACCESS_ADMIN => self::PERSONA_ADMIN === $persona,
            default => throw new \LogicException(sprintf('Unknown access level "%s".', $access)),
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function sendRequest(KernelBrowser $client, string $method, string $uri, string $body, array $payload, string $loginEmail): void
    {
        if (self::BODY_LOGIN_FORM === $body) {
            $client->request($method, $uri, ['_username' => $loginEmail, '_password' => self::LOGIN_PASSWORD]);

            return;
        }

        $client->request($method, $uri, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([] === $payload ? new \stdClass() : $payload, JSON_THROW_ON_ERROR));
    }

    /**
     * Swaps the id placeholders in a payload or query for the fixture's user ids.
     *
     * @param array<array-key, mixed> $values
     * @param array{playerIds: array<string, int>} $fixtures
     *
     * @return array<array-key, mixed>
     */
    private function resolveTokens(array $values, array $fixtures): array
    {
        $resolved = [];
        foreach ($values as $key => $value) {
            $resolved[$key] = match (true) {
                is_array($value) => $this->resolveTokens($value, $fixtures),
                self::TOKEN_PARTICIPANT_ID === $value => $fixtures['playerIds']['participant'],
                self::TOKEN_OPPONENT_ID === $value => $fixtures['playerIds']['opponent'],
                default => $value,
            };
        }

        return $resolved;
    }

    /**
     * @param array<string, string>                                                                   $parameters
     * @param array{personas: array<string, User>, playerIds: array<string, int>, loginEmail: string, gameId: int, invitationUuid: string} $fixtures
     *
     * @return array<string, int|string>
     */
    private function resolveParameters(array $parameters, array $fixtures): array
    {
        $resolved = [];
        foreach ($parameters as $name => $fixtureKey) {
            $resolved[$name] = match ($fixtureKey) {
                self::FIXTURE_GAME => $fixtures['gameId'],
                self::FIXTURE_MISSING_GAME => $fixtures['gameId'] + 1_000_000,
                self::FIXTURE_INVITATION => $fixtures['invitationUuid'],
                default => throw new \LogicException(sprintf('Unknown fixture "%s".', $fixtureKey)),
            };
        }

        return $resolved;
    }

    /**
     * Builds one game with an invitation, the five logged-in personas, an opponent for the
     * participant, and a user with a known password for the login route. The game is in the
     * lobby, started with an empty first round, or finished. DAMA rolls all of it back after
     * the test.
     *
     * @return array{personas: array<string, User>, playerIds: array<string, int>, loginEmail: string, gameId: int, invitationUuid: string}
     */
    private function createFixtures(string $state): array
    {
        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $passwordHasher = $container->get(UserPasswordHasherInterface::class);

        $personas = [
            self::PERSONA_ROLE_USER_ONLY => $this->createUser($entityManager, 'roleless', []),
            self::PERSONA_GUEST => $this->createUser($entityManager, 'guest', ['ROLE_GUEST']),
            self::PERSONA_PLAYER_OUTSIDER => $this->createUser($entityManager, 'outsider', ['ROLE_PLAYER']),
            self::PERSONA_PLAYER_PARTICIPANT => $this->createUser($entityManager, 'participant', ['ROLE_PLAYER']),
            self::PERSONA_ADMIN => $this->createUser($entityManager, 'admin', ['ROLE_ADMIN']),
        ];

        $opponent = $this->createUser($entityManager, 'opponent', ['ROLE_PLAYER']);
        $loginUser = $this->createUser($entityManager, 'login', ['ROLE_PLAYER']);
        $loginUser->setPassword($passwordHasher->hashPassword($loginUser, self::LOGIN_PASSWORD));

        $game = (new Game())
            ->setDate(new DateTime())
            ->setStartScore(301)
            ->setDoubleOut(false)
            ->setTripleOut(false)
            ->setStatus(match ($state) {
                self::STATE_LOBBY => GameStatus::Lobby,
                self::STATE_STARTED => GameStatus::Started,
                self::STATE_FINISHED => GameStatus::Finished,
                default => throw new \LogicException(sprintf('Unknown game state "%s".', $state)),
            });
        if (self::STATE_FINISHED === $state) {
            $game->setFinishedAt(new DateTimeImmutable());
        }
        $entityManager->persist($game);

        if (self::STATE_STARTED === $state) {
            $round = (new Round())->setRoundNumber(1)->setStartedAt(new DateTime());
            $game->addRound($round);
            $game->setRound(1);
        }

        $gamePlayer = (new GamePlayers())
            ->setGame($game)
            ->setPlayer($personas[self::PERSONA_PLAYER_PARTICIPANT])
            ->setPosition(1)
            ->setScore(301)
            ->setIsWinner(false);
        $game->addGamePlayer($gamePlayer);
        $entityManager->persist($gamePlayer);

        $opponentSeat = (new GamePlayers())
            ->setGame($game)
            ->setPlayer($opponent)
            ->setPosition(2)
            ->setScore(301)
            ->setIsWinner(false);
        $game->addGamePlayer($opponentSeat);
        $entityManager->persist($opponentSeat);
        $entityManager->flush();

        $gameId = $game->getGameId();
        self::assertNotNull($gameId);

        $invitationUuid = Uuid::v4()->toRfc4122();
        $invitation = (new Invitation())
            ->setUuid($invitationUuid)
            ->setGameId($gameId);
        $game->setInvitation($invitation);
        $entityManager->persist($invitation);
        $entityManager->flush();

        return [
            'personas' => $personas,
            'playerIds' => [
                'participant' => (int) $personas[self::PERSONA_PLAYER_PARTICIPANT]->getId(),
                'opponent' => (int) $opponent->getId(),
            ],
            'loginEmail' => (string) $loginUser->getEmail(),
            'gameId' => $gameId,
            'invitationUuid' => $invitationUuid,
        ];
    }

    /**
     * @param list<string> $roles
     */
    private function createUser(EntityManagerInterface $entityManager, string $label, array $roles): User
    {
        $suffix = bin2hex(random_bytes(4));
        $user = (new User())
            ->setEmail(sprintf('matrix-%s-%s@test.dev', $label, $suffix))
            ->setUsername(sprintf('matrix_%s_%s', $label, $suffix))
            ->setPassword('unused')
            ->setRoles($roles);
        $entityManager->persist($user);

        return $user;
    }

    /**
     * Captures every row of every application table, so a denied write that slipped
     * through shows up whichever table it touched.
     *
     * @return array<string, list<string>>
     */
    private function snapshotDatabase(): array
    {
        $connection = static::getContainer()->get(Connection::class);

        $snapshot = [];
        foreach ($connection->createSchemaManager()->listTableNames() as $table) {
            if ('doctrine_migration_versions' === $table) {
                continue;
            }

            $rows = array_map(
                static fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR),
                $connection->fetchAllAssociative(sprintf('SELECT * FROM %s', $connection->quoteSingleIdentifier($table))),
            );
            sort($rows);
            $snapshot[$table] = $rows;
        }

        ksort($snapshot);

        return $snapshot;
    }
}
