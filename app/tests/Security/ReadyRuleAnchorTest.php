<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Security;

use App\Tests\Support\PayloadCode\PayloadCodeKernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * The PUBLIC_ACCESS rule for /api/ready is anchored: a routed path with an extra segment
 * still falls through to the `^/api` rule and gets 401 (SECURITY.md, Access control).
 */
final class ReadyRuleAnchorTest extends WebTestCase
{
    #[\Override]
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new PayloadCodeKernel('test', true);
    }

    public function testPathWithExtraSegmentIsNotPublic(): void
    {
        $client = static::createClient();

        $client->request('GET', PayloadCodeKernel::NEXT_TO_READY_PATH);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());
    }
}
