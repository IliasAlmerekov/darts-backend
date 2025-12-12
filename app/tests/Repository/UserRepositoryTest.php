<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

final class UserRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private UserRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropDatabase();
        $schemaTool->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());

        $this->repository = static::getContainer()->get(UserRepository::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager->close();
    }

    public function testFindByIdsReturnsMatchingUsers(): void
    {
        $user1 = $this->createUser('alpha');
        $user2 = $this->createUser('beta');
        $user3 = $this->createUser('gamma');
        $this->entityManager->flush();

        $result = $this->repository->findByIds([$user1->getId(), $user3->getId()]);

        $ids = array_map(static fn (User $u): int => $u->getId(), $result);
        sort($ids);

        self::assertSame([$user1->getId(), $user3->getId()], $ids);
        self::assertNotContains($user2->getId(), $ids);
    }

    public function testFindByIdsWithEmptyInputReturnsEmptyArray(): void
    {
        self::assertSame([], $this->repository->findByIds([]));
    }

    public function testUpgradePasswordUpdatesStoredPassword(): void
    {
        $user = $this->createUser('delta', password: 'old_hash');
        $this->entityManager->flush();
        $this->entityManager->clear();

        $managed = $this->repository->find($user->getId());
        self::assertNotNull($managed);

        $this->repository->upgradePassword($managed, 'new_hash');
        $this->entityManager->clear();

        $reloaded = $this->repository->find($user->getId());
        self::assertSame('new_hash', $reloaded?->getPassword());
    }

    public function testUpgradePasswordThrowsOnUnsupportedUser(): void
    {
        $unsupportedUser = new class () implements PasswordAuthenticatedUserInterface {
            public function getPassword(): ?string
            {
                return null;
            }
        };

        $this->expectException(UnsupportedUserException::class);
        $this->repository->upgradePassword($unsupportedUser, 'hash');
    }

    private function createUser(string $username, string $password = 'secret'): User
    {
        static $counter = 1;

        $user = (new User())
            ->setUsername($username)
            ->setEmail(sprintf('%s%d@test.dev', $username, $counter++))
            ->setPassword($password);

        $this->entityManager->persist($user);

        return $user;
    }
}
