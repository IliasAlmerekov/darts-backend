<?php

namespace App\Tests;

use App\Repository\GamePlayersRepository;
use App\Repository\GameRepository;
use App\Repository\InvitationRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AblaufTest extends WebTestCase
{
    public function testCreateGame(): void
    {
        $client = static::createClient();
        $userRepository = static::getContainer()->get(\App\Repository\UserRepository::class);
        $gameRepository = static::getContainer()->get(GameRepository::class);
        $testUser = $userRepository->findOneByEmail('admin@gmail.com');
        $client->loginUser($testUser);

        $gameCountBefore = count($gameRepository->findAll());
        $client->request('POST', '/room/create');
        $createdGame = $gameRepository->findOneBy([], ['gameId' => 'DESC']);
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

        $crawler = $client->request('GET', "/invite/create/{$gameId}");
        $this->assertResponseIsSuccessful();

        $invitationCountAfter = count($invitationRepository->findAll());
        $this->assertEquals($invitationCountBefore + 1, $invitationCountAfter);

        $invitation = $invitationRepository->findOneByGameId($gameId);
        self::assertNotNull($invitation);

        $uuid = $invitation->getUuid();
        $expectedUrl = 'http://localhost/invite/join/' . $uuid;
        $actualInvitationLink = $crawler->filter('#roomLink')->attr('value');

        $this->assertEquals($expectedUrl, $actualInvitationLink);
    }

    public function testJoinInvitation(): void
    {
        $client = static::createClient();
        $userRepository = static::getContainer()->get(\App\Repository\UserRepository::class);
        $invitationRepository = static::getContainer()->get(InvitationRepository::class);
        $gameRepository = static::getContainer()->get(GameRepository::class);
        $gamePlayerRepository = static::getContainer()->get(GamePlayersRepository::class);
        $testUser = $userRepository->findOneByEmail('ilias.teknis@db-n.com');
        $client->loginUser($testUser);

        $highestGameId = $gameRepository->findOneBy([], ['gameId' => 'DESC']);
        $invitation = $invitationRepository->findOneByGameId($highestGameId);
        $uuid = $invitation->getUuid();

        $client->request('GET', "/invite/join/{$uuid}");
        $this->assertResponseRedirects('/login');

        $session = $client->getRequest()->getSession();
        $this->assertEquals($uuid, $session->get('invitation_uuid'));

        $client->followRedirect();
        $this->assertResponseRedirects('/login/success');
        $client->followRedirect();
        $this->assertResponseRedirects('/room/waiting');

//      Der Spieler wird erfolgreich dem Game in der Datenbank hinzugefügt
        $gamePlayer = $gamePlayerRepository->findOneBy(['gameId' => $highestGameId]);
        $userId = $gamePlayer->getPlayerId();
        $this->assertEquals($userId, $testUser->getId());
    }
}
