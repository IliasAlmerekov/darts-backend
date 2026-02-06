<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ApiAccessMatrixTest extends WebTestCase
{
    /**
     * @param string      $method
     * @param string      $uri
     * @param string|null $content
     *
     * @return void
     */
    #[DataProvider('protectedApiRoutesProvider')]
    public function testAnonymousUserGetsUnauthorized(string $method, string $uri, ?string $content = null): void
    {
        $client = static::createClient();
        $client->request(
            $method,
            $uri,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $content ?? ''
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    /**
     * @param string      $method
     * @param string      $uri
     * @param string|null $content
     *
     * @return void
     */
    #[DataProvider('protectedApiRoutesProvider')]
    public function testAuthenticatedUserWithoutRequiredRoleGetsForbidden(string $method, string $uri, ?string $content = null): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUserWithRoles([]));
        $client->request(
            $method,
            $uri,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $content ?? ''
        );

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2?: string|null}>
     */
    public static function protectedApiRoutesProvider(): array
    {
        return [
            'game_state' => [Request::METHOD_GET, '/api/game/1'],
            'room_create' => [Request::METHOD_POST, '/api/room/create', '{}'],
            'invite_create' => [Request::METHOD_POST, '/api/invite/create/1'],
        ];
    }

    /**
     * @param list<string> $roles
     *
     * @return User
     */
    private function createUserWithRoles(array $roles): User
    {
        $user = (new User())
            ->setEmail(sprintf('access-matrix-%s@test.dev', uniqid('', true)))
            ->setUsername(sprintf('matrix_%s', uniqid()))
            ->setPassword('unused')
            ->setRoles($roles);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }
}
