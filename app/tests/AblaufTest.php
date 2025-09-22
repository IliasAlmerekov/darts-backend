<?php

namespace App\Tests;

use App\Repository\GameRepository;
use App\Repository\InvitationRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AblaufTest extends WebTestCase
{
    public function testCreateGame(): void
    {
        $client = static::createClient();
        $userRepository = static::getContainer()->get(\App\Repository\UserRepository::class);
        $testUser = $userRepository->findOneByEmail('admin@gmail.com');
        $client->loginUser($testUser);

        $gameRepository = static::getContainer()->get(GameRepository::class);
        $gameCountBefore = count($gameRepository->findAll());
        $highestGameId = $gameRepository->findHighestGameId();
        $client->request('POST', '/room/create');
        $createdGame = $gameRepository->findOneByGameId($highestGameId + 1);
        $expectedUrl = '/invite/create/' . $createdGame->getGameId();
        $this->assertResponseRedirects($expectedUrl);
        $gameCountAfter = count($gameRepository->findAll());
        $this->assertEquals($gameCountBefore + 1, $gameCountAfter);
    }

    public function testCreateInvitation(): void
    {
        $client = static::createClient();
        $userRepository = static::getContainer()->get(\App\Repository\UserRepository::class);
        $invitationRepository = static::getContainer()->get(InvitationRepository::class);
        $gameRepository = static::getContainer()->get(GameRepository::class);
        $testUser = $userRepository->findOneByEmail('admin@gmail.com');
        $client->loginUser($testUser);
        $latestGame = $gameRepository->findOneBy([], ['gameId' => 'DESC']);
        $gameId = $latestGame->getGameId();

        $invitationCountBefore = count($invitationRepository->findAll());

        $client->request('GET', "/invite/create/{$gameId}");
        $this->assertResponseIsSuccessful();

        $invitationCountAfter = count($invitationRepository->findAll());
        $this->assertEquals($invitationCountBefore + 1, $invitationCountAfter);

        $invitation = $invitationRepository->findOneByGameId($gameId);
        self::assertNotNull($invitation);
        $uuid = $invitation->getUuid();
        $invitationUrl = '/invite/join/' . $uuid;

        $this->assertStringContainsString('/invite/join/', $invitationUrl);
    }

    public function testJoinInvitation(): void
    {
        $client = static::createClient();
        $userRepository = static::getContainer()->get(\App\Repository\UserRepository::class);
        $testUser = $userRepository->findOneByEmail('ilias.teknis@db-n.com');
    }
}
