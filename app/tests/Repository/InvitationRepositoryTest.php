<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Invitation;
use App\Repository\InvitationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class InvitationRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private InvitationRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = static::$kernel->getContainer()->get(EntityManagerInterface::class);
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropDatabase();
        $schemaTool->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());

        $this->repository = static::$kernel->getContainer()->get(InvitationRepository::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager->close();
    }

    public function testPersistAndFind(): void
    {
        $invitation = $this->createInvitation(42);
        $this->entityManager->flush();

        $found = $this->repository->find($invitation->getId());

        self::assertNotNull($found);
        self::assertSame($invitation->getId(), $found->getId());
        self::assertSame($invitation->getUuid(), $found->getUuid());
        self::assertSame($invitation->getGameId(), $found->getGameId());
    }

    public function testFindOneByUuid(): void
    {
        $invitation = $this->createInvitation(7);
        $this->entityManager->flush();

        $found = $this->repository->findOneBy(['uuid' => $invitation->getUuid()]);

        self::assertNotNull($found);
        self::assertSame($invitation->getUuid(), $found->getUuid());
        self::assertSame($invitation->getGameId(), $found->getGameId());
    }

    private function createInvitation(int $gameId): Invitation
    {
        $invitation = (new Invitation())
            ->setUuid(Uuid::v4())
            ->setGameId($gameId);

        $this->entityManager->persist($invitation);

        return $invitation;
    }
}
