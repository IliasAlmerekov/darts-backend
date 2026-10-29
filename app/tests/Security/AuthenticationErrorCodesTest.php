<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Game;
use App\Entity\GamePlayers;
use App\Enum\GameStatus;
use App\Exception\Request\UsernameAlreadyTakenException;
use App\Exception\Security\SecurityAccessDeniedException;
use App\Exception\Security\UserNotAuthenticatedException;
use App\Controller\ErrorController;
use App\Tests\Support\TestUserFactory;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * One code for unauthenticated (401 AUTHENTICATION_REQUIRED) and forbidden (403 ACCESS_DENIED),
 * 204 from logout, and USERNAME_TAKEN through the shared error renderer (#178).
 */
final class AuthenticationErrorCodesTest extends WebTestCase
{
    public function testAnonymousLoginSuccessAnswers401AuthenticationRequired(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_GET, '/api/login/success');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSame(
            ['success' => false, 'error' => 'AUTHENTICATION_REQUIRED', 'message' => 'Unauthorized'],
            json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testPlayerCallingAnAdminRouteAnswers403AccessDenied(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $player = TestUserFactory::create($entityManager, 'authcodes', 'player', ['ROLE_PLAYER']);
        $entityManager->flush();
        $client->loginUser($player);

        $client->request(Request::METHOD_POST, '/api/room/create');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('ACCESS_DENIED', $body['error'] ?? null);
    }

    public function testLogoutAnswers204WithoutBodyAndEndsTheSession(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $player = TestUserFactory::create($entityManager, 'authcodes', 'logout', ['ROLE_PLAYER']);
        $entityManager->flush();
        $client->loginUser($player);
        $client->request(Request::METHOD_GET, '/api/login/success');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $client->request(Request::METHOD_POST, '/api/logout');

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertSame('', (string) $client->getResponse()->getContent());
        $client->request(Request::METHOD_GET, '/api/login/success');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testAddingAGuestWithATakenNameAnswers409UsernameTakenWithSuggestions(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $admin = TestUserFactory::create($entityManager, 'authcodes', 'admin', ['ROLE_ADMIN']);
        $taken = TestUserFactory::create($entityManager, 'authcodes', 'taken', ['ROLE_PLAYER'])->setDisplayName('Alex');
        $game = (new Game())->setDate(new DateTime())->setStartScore(301)->setStatus(GameStatus::Lobby)->setRound(1);
        $entityManager->persist($game);
        $seat = (new GamePlayers())->setPlayer($taken)->setScore(301)->setPosition(1);
        $game->addGamePlayer($seat);
        $entityManager->persist($seat);
        $entityManager->flush();
        $client->loginUser($admin);

        $client->request(Request::METHOD_POST, sprintf('/api/room/%d/guest', (int) $game->getGameId()), [], [], ['CONTENT_TYPE' => 'application/json'], '{"username":"Alex"}');

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertFalse($body['success'] ?? null);
        self::assertSame('USERNAME_TAKEN', $body['error'] ?? null);
        self::assertSame('The name is already taken', $body['message'] ?? null);
        self::assertIsArray($body['suggestions'] ?? null);
    }

    public function testErrorControllerRendersAccessDeniedExceptionAs403AccessDenied(): void
    {
        $response = (new ErrorController())->show(new SecurityAccessDeniedException());

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertSame('ACCESS_DENIED', $this->body($response)['error'] ?? null);
    }

    public function testErrorControllerRendersNotAuthenticatedExceptionAs401AuthenticationRequired(): void
    {
        $response = (new ErrorController())->show(new UserNotAuthenticatedException());

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertSame('AUTHENTICATION_REQUIRED', $this->body($response)['error'] ?? null);
    }

    public function testErrorControllerMapsAPlain401ToAuthenticationRequired(): void
    {
        $response = (new ErrorController())->show(new HttpException(Response::HTTP_UNAUTHORIZED));

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertSame('AUTHENTICATION_REQUIRED', $this->body($response)['error'] ?? null);
    }

    public function testErrorControllerRendersUsernameTakenWithSuggestions(): void
    {
        $response = (new ErrorController())->show(new UsernameAlreadyTakenException('Alex', ['Alex 2', 'Alex 3']));

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        self::assertSame(
            ['success' => false, 'error' => 'USERNAME_TAKEN', 'message' => 'The name is already taken', 'suggestions' => ['Alex 2', 'Alex 3']],
            $this->body($response),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function body(Response $response): array
    {
        $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }
}
