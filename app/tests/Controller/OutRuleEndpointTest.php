<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Game;
use App\Entity\GamePlayers;
use App\Entity\User;
use App\Enum\GameStatus;
use App\Tests\Support\TestUserFactory;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A game has exactly one out rule, and it is locked once the game has its first throw.
 */
final class OutRuleEndpointTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private User $admin;
    private User $first;
    private User $second;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->admin = TestUserFactory::create($this->entityManager, 'outrule', 'admin', ['ROLE_ADMIN']);
        $this->first = TestUserFactory::create($this->entityManager, 'outrule', 'first', ['ROLE_PLAYER']);
        $this->second = TestUserFactory::create($this->entityManager, 'outrule', 'second', ['ROLE_PLAYER']);
        $this->entityManager->flush();
    }

    public function testRoomCreateRejectsDoubleAndTripleOutAndKeepsTheLobby(): void
    {
        $lobby = $this->createGame(GameStatus::Lobby);

        $response = $this->request(Request::METHOD_POST, '/api/room/create', ['startScore' => 501, 'doubleOut' => true, 'tripleOut' => true]);

        $this->assertConflictingOutRules($response);
        self::assertSame(301, $this->assertOutRule($lobby, false, false)->getStartScore());
    }

    public function testRoomCreateWithoutLobbyRejectsDoubleAndTripleOutAndOpensNoLobby(): void
    {
        self::assertSame(0, $this->countLobbies());
        $gameCount = $this->countGames();

        $response = $this->request(Request::METHOD_POST, '/api/room/create', [
            'playerIds' => [(int) $this->first->getId(), (int) $this->second->getId()],
            'doubleOut' => true,
            'tripleOut' => true,
        ]);

        $this->assertConflictingOutRules($response);
        self::assertSame($gameCount, $this->countGames());
        self::assertSame(0, $this->countLobbies());
    }

    public function testSettingsRejectDoubleAndTripleOut(): void
    {
        $lobby = $this->createGame(GameStatus::Lobby);

        $response = $this->patchSettings($lobby, ['startScore' => 501, 'doubleOut' => true, 'tripleOut' => true]);

        $this->assertConflictingOutRules($response);
        self::assertSame(301, $this->assertOutRule($lobby, false, false)->getStartScore());
    }

    public function testSettingsRejectTripleOutOnADoubleOutGame(): void
    {
        $lobby = $this->createGame(GameStatus::Lobby, doubleOut: true);

        $response = $this->patchSettings($lobby, ['tripleOut' => true]);

        $this->assertConflictingOutRules($response);
        $this->assertOutRule($lobby, true, false);
    }

    public function testSettingsRejectDoubleAndTripleOutAlongsideOutMode(): void
    {
        $lobby = $this->createGame(GameStatus::Lobby, doubleOut: true);

        $response = $this->patchSettings($lobby, ['outMode' => 'singleout', 'doubleOut' => true, 'tripleOut' => true]);

        $this->assertConflictingOutRules($response);
        $this->assertOutRule($lobby, true, false);
    }

    public function testSettingsOutModeAloneReplacesBothFlags(): void
    {
        $lobby = $this->createGame(GameStatus::Lobby, doubleOut: true);

        $this->patchSettings($lobby, ['outMode' => 'tripleout']);

        self::assertResponseIsSuccessful();
        $this->assertOutRule($lobby, false, true);
    }

    public function testTripleOutIsReportedAndRequiresATripleToCheckout(): void
    {
        $room = $this->request(Request::METHOD_POST, '/api/room/create', [
            'playerIds' => [(int) $this->first->getId(), (int) $this->second->getId()],
            'tripleOut' => true,
        ]);
        self::assertResponseIsSuccessful();
        $game = $this->entityManager->find(Game::class, $room['gameId']);
        self::assertInstanceOf(Game::class, $game);
        $this->request(Request::METHOD_POST, sprintf('/api/game/%d/start', (int) $game->getGameId()));
        self::assertResponseIsSuccessful();
        $seat = $this->entityManager->getRepository(GamePlayers::class)->findOneBy(['game' => $game, 'player' => $this->first]);
        self::assertInstanceOf(GamePlayers::class, $seat);
        $seat->setScore(18);
        $this->entityManager->flush();
        $settings = $this->request(Request::METHOD_GET, sprintf('/api/game/%d/settings', (int) $game->getGameId()));
        self::assertTrue($settings['tripleOut']);
        self::assertFalse($settings['doubleOut']);

        $this->throwDart($game, 18);
        $seat = $this->entityManager->getRepository(GamePlayers::class)->findOneBy(['game' => $game, 'player' => $this->first]);
        self::assertInstanceOf(GamePlayers::class, $seat);
        self::assertSame(18, $seat->getScore());
        $this->undo($game);
        $seat = $this->entityManager->getRepository(GamePlayers::class)->findOneBy(['game' => $game, 'player' => $this->first]);
        self::assertInstanceOf(GamePlayers::class, $seat);
        $seat->setScore(18);
        $this->entityManager->flush();
        $this->request(Request::METHOD_POST, $this->throwUri($game), ['playerId' => (int) $this->first->getId(), 'value' => 6, 'isTriple' => true]);
        self::assertResponseIsSuccessful();
        $seat = $this->entityManager->getRepository(GamePlayers::class)->findOneBy(['game' => $game, 'player' => $this->first]);
        self::assertInstanceOf(GamePlayers::class, $seat);
        self::assertSame(0, $seat->getScore());
    }

    public function testStartRejectsDoubleAndTripleOutAndKeepsTheLobby(): void
    {
        $lobby = $this->createGame(GameStatus::Lobby);

        $response = $this->request(Request::METHOD_POST, sprintf('/api/game/%d/start', (int) $lobby->getGameId()), ['doubleOut' => true, 'tripleOut' => true]);

        $this->assertConflictingOutRules($response);
        $reloaded = $this->assertOutRule($lobby, false, false);
        self::assertSame(GameStatus::Lobby, $reloaded->getStatus());
    }

    public function testStartedGameWithoutThrowsAcceptsAnOutRuleChange(): void
    {
        $game = $this->createGame(GameStatus::Started);

        $this->patchSettings($game, ['outMode' => 'doubleout']);

        self::assertResponseIsSuccessful();
        $this->assertOutRule($game, true, false);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function firstThrows(): iterable
    {
        yield 'hit' => [301, 20];
        yield 'miss' => [301, 0];
        yield 'bust' => [10, 20];
    }

    #[DataProvider('firstThrows')]
    public function testOutRuleChangeAfterTheFirstThrowIsRejected(int $score, int $value): void
    {
        $game = $this->createGame(GameStatus::Started, score: $score);
        $this->throwDart($game, $value);

        $response = $this->patchSettings($game, ['outMode' => 'doubleout']);

        $this->assertOutRuleLocked($response);
        $this->assertOutRule($game, false, false);
    }

    public function testFlagChangeAfterTheFirstThrowIsRejected(): void
    {
        $game = $this->createGame(GameStatus::Started);
        $this->throwDart($game, 20);

        $response = $this->patchSettings($game, ['tripleOut' => true]);

        $this->assertOutRuleLocked($response);
        $this->assertOutRule($game, false, false);
    }

    public function testUnchangedOutRuleAfterTheFirstThrowIsRejectedLikeAnUnchangedStartScore(): void
    {
        $game = $this->createGame(GameStatus::Started);
        $this->throwDart($game, 20);

        $response = $this->patchSettings($game, ['outMode' => 'singleout']);

        $this->assertOutRuleLocked($response);
    }

    public function testOutRuleChangeIsAcceptedAgainAfterUndoingEveryThrow(): void
    {
        $game = $this->createGame(GameStatus::Started);
        $this->throwDart($game, 20);
        $this->throwDart($game, 5);
        $this->undo($game);
        $this->undo($game);

        $this->patchSettings($game, ['outMode' => 'doubleout']);

        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        $this->assertOutRule($game, true, false);
    }

    private function createGame(GameStatus $status, bool $doubleOut = false, int $score = 301): Game
    {
        $game = (new Game())
            ->setDate(new DateTime())
            ->setStatus($status)
            ->setStartScore(301)
            ->setOutRule($doubleOut ? \App\Enum\OutRule::Double : \App\Enum\OutRule::Single);
        if (GameStatus::Started === $status) {
            $game->setRound(1);
        }
        $this->entityManager->persist($game);
        foreach ([1 => $this->first, 2 => $this->second] as $position => $player) {
            $seat = (new GamePlayers())->setPlayer($player)->setPosition($position)->setScore($score);
            $game->addGamePlayer($seat);
            $this->entityManager->persist($seat);
        }
        $this->entityManager->flush();

        return $game;
    }

    private function throwDart(Game $game, int $value): void
    {
        $this->request(Request::METHOD_POST, $this->throwUri($game), ['playerId' => (int) $this->first->getId(), 'value' => $value]);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
    }

    private function undo(Game $game): void
    {
        $this->request(Request::METHOD_DELETE, $this->throwUri($game));
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
    }

    private function throwUri(Game $game): string
    {
        return sprintf('/api/game/%d/throw/delta', (int) $game->getGameId());
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<array-key, mixed>
     */
    private function patchSettings(Game $game, array $body): array
    {
        return $this->request(Request::METHOD_PATCH, sprintf('/api/game/%d/settings', (int) $game->getGameId()), $body);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<array-key, mixed>
     */
    private function request(string $method, string $uri, array $body = []): array
    {
        $this->client->loginUser($this->admin);
        $this->client->request($method, $uri, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
        $this->entityManager->clear();
        $content = (string) $this->client->getResponse()->getContent();
        $payload = '' === $content ? [] : json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
    }

    /**
     * @param array<array-key, mixed> $response
     */
    private function assertConflictingOutRules(array $response): void
    {
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, (string) $this->client->getResponse()->getContent());
        self::assertSame('GAME_CONFLICTING_OUT_RULES', $response['error'] ?? null);
    }

    /**
     * @param array<array-key, mixed> $response
     */
    private function assertOutRuleLocked(array $response): void
    {
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, (string) $this->client->getResponse()->getContent());
        self::assertSame('GAME_OUT_RULE_CHANGE_NOT_ALLOWED', $response['error'] ?? null);
        self::assertSame('The out rule cannot be changed after the first throw.', $response['message'] ?? null);
    }

    private function assertOutRule(Game $game, bool $doubleOut, bool $tripleOut): Game
    {
        $reloaded = $this->entityManager->find(Game::class, $game->getGameId());
        self::assertInstanceOf(Game::class, $reloaded);
        self::assertSame($doubleOut, $reloaded->isDoubleOut());
        self::assertSame($tripleOut, $reloaded->isTripleOut());

        return $reloaded;
    }

    private function countGames(): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM game');
    }

    private function countLobbies(): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM game WHERE status = ?', [GameStatus::Lobby->value]);
    }
}
