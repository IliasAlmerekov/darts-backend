<?php

declare(strict_types=1);

namespace App\Tests\Service\Persistence;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * RetryingTransaction relies on resetManager() reopening the entity manager that services hold.
 */
final class EntityManagerResetTest extends KernelTestCase
{
    public function testResetManagerReopensTheInjectedEntityManager(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->close();

        static::getContainer()->get(ManagerRegistry::class)->resetManager();

        self::assertTrue($entityManager->isOpen());
    }
}
