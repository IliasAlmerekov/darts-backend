<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Game;
use App\Entity\GamePlayers;
use App\Entity\Invitation;
use App\Entity\User;
use App\Enum\GameStatus;
use DateTime;
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

    private const string LOGIN_PASSWORD = 'matrix-secret';

    /**
     * Route parameters map to fixture keys so the URIs point at real rows.
     *
     * `room_stream` points at a missing game on purpose: for an allowed caller the
     * controller would otherwise open an endless SSE loop, and the 404 it returns
     * instead still proves the request passed the access rules.
     *
     * @return list<array{route: string, method: string, access: string, parameters: array<string, string>, body: string}>
     */
    private static function matrix(): array
    {
        $game = ['gameId' => self::FIXTURE_GAME];
        $room = ['id' => self::FIXTURE_GAME];

        return [
            // Public
            self::entry('app_login', Request::METHOD_GET, self::ACCESS_PUBLIC),
            self::entry('app_login', Request::METHOD_POST, self::ACCESS_PUBLIC, body: self::BODY_LOGIN_FORM),
            self::entry('login_success', self::METHOD_ANY, self::ACCESS_PUBLIC),
            self::entry('app_logout', self::METHOD_ANY, self::ACCESS_PUBLIC),
            self::entry('app_register', Request::METHOD_POST, self::ACCESS_PUBLIC),
            self::entry('app_csrf_tokens', Request::METHOD_GET, self::ACCESS_PUBLIC),
            self::entry('api_health', Request::METHOD_GET, self::ACCESS_PUBLIC),
            self::entry('join_invitation', self::METHOD_ANY, self::ACCESS_PUBLIC, ['uuid' => self::FIXTURE_INVITATION]),

            // Players
            self::entry('process_invitation', Request::METHOD_POST, self::ACCESS_PLAYER),

            // Admin: invitations
            self::entry('create_invitation', Request::METHOD_POST, self::ACCESS_ADMIN, $room),

            // Admin: rooms
            self::entry('room_create', Request::METHOD_POST, self::ACCESS_ADMIN),
            self::entry('room_player_leave', Request::METHOD_DELETE, self::ACCESS_ADMIN, $room),
            self::entry('room_player_guest_add', Request::METHOD_POST, self::ACCESS_ADMIN, $room),
            self::entry('room_update_player_positions', Request::METHOD_POST, self::ACCESS_ADMIN, $room),
            self::entry('room_stream', Request::METHOD_GET, self::ACCESS_ADMIN, ['id' => self::FIXTURE_MISSING_GAME]),
            self::entry('room_rematch', Request::METHOD_POST, self::ACCESS_ADMIN, $room),

            // Admin: game lifecycle
            self::entry('app_game_state', Request::METHOD_GET, self::ACCESS_ADMIN, $game),
            self::entry('app_game_start', Request::METHOD_POST, self::ACCESS_ADMIN, $game),
            self::entry('app_game_rematch_start', Request::METHOD_POST, self::ACCESS_ADMIN, $game),
            self::entry('app_game_settings_create', Request::METHOD_POST, self::ACCESS_ADMIN),
            self::entry('app_game_settings', Request::METHOD_PATCH, self::ACCESS_ADMIN, $game),
            self::entry('app_game_settings_read', Request::METHOD_GET, self::ACCESS_ADMIN, $game),
            self::entry('app_game_finish', Request::METHOD_POST, self::ACCESS_ADMIN, $game),
            self::entry('app_game_reopen', Request::METHOD_PATCH, self::ACCESS_ADMIN, $game),
            self::entry('app_game_finished', Request::METHOD_GET, self::ACCESS_ADMIN, $game),
            self::entry('app_games_finished', Request::METHOD_GET, self::ACCESS_ADMIN, $game),
            self::entry('app_game_abort', Request::METHOD_PATCH, self::ACCESS_ADMIN, $game),

            // Admin: throws
            self::entry('app_game_throw', Request::METHOD_POST, self::ACCESS_ADMIN, $game),
            self::entry('app_game_throw_delta', Request::METHOD_POST, self::ACCESS_ADMIN, $game),
            self::entry('app_game_throw_undo', Request::METHOD_DELETE, self::ACCESS_ADMIN, $game),
            self::entry('app_game_throw_undo_delta', Request::METHOD_DELETE, self::ACCESS_ADMIN, $game),

            // Admin: statistics
            self::entry('app_games_overview', Request::METHOD_GET, self::ACCESS_ADMIN),
            self::entry('app_games_details', Request::METHOD_GET, self::ACCESS_ADMIN, $game),
            self::entry('app_players_stats', Request::METHOD_GET, self::ACCESS_ADMIN),
        ];
    }

    /**
     * @return iterable<string, array{string, string, string, array<string, string>, string, string}>
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
                $name = sprintf('%s %s as %s', $entry['method'], $entry['route'], $persona);

                yield $name => [$entry['route'], $entry['method'], $entry['access'], $entry['parameters'], $entry['body'], $persona];
            }
        }
    }

    /**
     * @param array<string, string> $parameters
     */
    #[DataProvider('accessCaseProvider')]
    public function testRouteAccessForPersona(string $route, string $method, string $access, array $parameters, string $body, string $persona): void
    {
        $client = static::createClient();
        $fixtures = $this->createFixtures();

        if (self::PERSONA_ANONYMOUS !== $persona) {
            $client->loginUser($fixtures['personas'][$persona]);
        }

        $router = static::getContainer()->get(RouterInterface::class);
        $uri = $router->generate($route, $this->resolveParameters($parameters, $fixtures));
        $httpMethod = self::METHOD_ANY === $method ? Request::METHOD_GET : $method;

        $snapshotBefore = $this->snapshotDatabase();
        $this->sendRequest($client, $httpMethod, $uri, $body, $fixtures['loginEmail']);
        $status = $client->getResponse()->getStatusCode();

        if (self::isAllowed($access, $persona)) {
            self::assertNotContains(
                $status,
                [Response::HTTP_UNAUTHORIZED, Response::HTTP_FORBIDDEN],
                sprintf('%s %s should be reachable for %s, got %d.', $httpMethod, $uri, $persona, $status),
            );

            return;
        }

        $expectedStatus = self::PERSONA_ANONYMOUS === $persona ? Response::HTTP_UNAUTHORIZED : Response::HTTP_FORBIDDEN;
        self::assertSame(
            $expectedStatus,
            $status,
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
     * @param array<string, string> $parameters
     *
     * @return array{route: string, method: string, access: string, parameters: array<string, string>, body: string}
     */
    private static function entry(string $route, string $method, string $access, array $parameters = [], string $body = self::BODY_JSON): array
    {
        return ['route' => $route, 'method' => $method, 'access' => $access, 'parameters' => $parameters, 'body' => $body];
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

    private function sendRequest(KernelBrowser $client, string $method, string $uri, string $body, string $loginEmail): void
    {
        if (self::BODY_LOGIN_FORM === $body) {
            $client->request($method, $uri, ['_username' => $loginEmail, '_password' => self::LOGIN_PASSWORD]);

            return;
        }

        $client->request($method, $uri, [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
    }

    /**
     * @param array<string, string>                                                                   $parameters
     * @param array{personas: array<string, User>, loginEmail: string, gameId: int, invitationUuid: string} $fixtures
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
     * Builds one lobby game with an invitation, the five logged-in personas, and a user
     * with a known password for the login route. DAMA rolls all of it back after the test.
     *
     * @return array{personas: array<string, User>, loginEmail: string, gameId: int, invitationUuid: string}
     */
    private function createFixtures(): array
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

        $loginUser = $this->createUser($entityManager, 'login', ['ROLE_PLAYER']);
        $loginUser->setPassword($passwordHasher->hashPassword($loginUser, self::LOGIN_PASSWORD));

        $game = (new Game())
            ->setDate(new DateTime())
            ->setStartScore(301)
            ->setDoubleOut(false)
            ->setTripleOut(false)
            ->setStatus(GameStatus::Lobby);
        $entityManager->persist($game);

        $gamePlayer = (new GamePlayers())
            ->setGame($game)
            ->setPlayer($personas[self::PERSONA_PLAYER_PARTICIPANT])
            ->setPosition(1)
            ->setScore(301)
            ->setIsWinner(false);
        $game->addGamePlayer($gamePlayer);
        $entityManager->persist($gamePlayer);
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
