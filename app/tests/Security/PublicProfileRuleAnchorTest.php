<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Security;

use App\Tests\Support\PayloadCode\PayloadCodeKernel;
use App\Tests\Support\TestUserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * The ROLE_PLAYER rule for the Public profile is anchored: a routed path with an extra
 * segment still falls through to the `^/api` rule and is denied to a player (SECURITY.md,
 * Access control).
 */
final class PublicProfileRuleAnchorTest extends WebTestCase
{
    #[\Override]
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new PayloadCodeKernel('test', true);
    }

    public function testPathWithExtraSegmentIsDeniedToAPlayer(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $player = TestUserFactory::create($entityManager, 'anchor', 'player', ['ROLE_PLAYER']);
        $entityManager->flush();
        $client->loginUser($player);

        $client->request('GET', PayloadCodeKernel::NEXT_TO_PUBLIC_PROFILE_PATH);

        self::assertSame(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('ACCESS_DENIED', (string) $client->getResponse()->getContent());
    }
}
