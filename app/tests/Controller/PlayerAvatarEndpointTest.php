<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\Support\TestUserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PlayerAvatarEndpointTest extends WebTestCase
{
    private ?string $originalEnv = null;
    private ?string $originalServer = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalEnv = $_ENV['AVATAR_DIR'] ?? null;
        $this->originalServer = $_SERVER['AVATAR_DIR'] ?? null;
    }

    protected function tearDown(): void
    {
        if (null === $this->originalEnv) {
            unset($_ENV['AVATAR_DIR']);
        } else {
            $_ENV['AVATAR_DIR'] = $this->originalEnv;
        }
        if (null === $this->originalServer) {
            unset($_SERVER['AVATAR_DIR']);
        } else {
            $_SERVER['AVATAR_DIR'] = $this->originalServer;
        }
        parent::tearDown();
    }

    public function testAdminGetsAvatarAndConditionalResponse(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $player = TestUserFactory::create($em, 'avatar', 'player', ['ROLE_PLAYER']);
        $admin = TestUserFactory::create($em, 'avatar', 'admin', ['ROLE_ADMIN']);
        $em->flush();
        $dir = sys_get_temp_dir().'/darts-avatar-'.bin2hex(random_bytes(8));
        mkdir($dir);
        $_ENV['AVATAR_DIR'] = $_SERVER['AVATAR_DIR'] = $dir;
        $path = $dir.'/'.strtolower((string) $player->getEmail()).'.jpg';
        $image = imagecreatetruecolor(2, 2);
        self::assertNotFalse($image);
        imagejpeg($image, $path);
        $bytes = file_get_contents($path);
        $client->loginUser($admin);
        try {
            $url = '/api/player/'.$player->getId().'/avatar';
            $client->request('GET', $url);
            self::assertResponseStatusCodeSame(200);
            self::assertSame($bytes, $client->getResponse()->getContent());
            self::assertSame('image/jpeg', $client->getResponse()->headers->get('Content-Type'));
            self::assertSame('no-cache, private', $client->getResponse()->headers->get('Cache-Control'));
            $etag = $client->getResponse()->headers->get('ETag');
            self::assertNotNull($etag);
            $client->request('GET', $url, server: ['HTTP_IF_NONE_MATCH' => $etag]);
            self::assertResponseStatusCodeSame(304);
            self::assertSame('', $client->getResponse()->getContent());
            imagesetpixel($image, 0, 0, 0xffffff);
            imagejpeg($image, $path);
            $client->request('GET', $url, server: ['HTTP_IF_NONE_MATCH' => $etag]);
            self::assertResponseStatusCodeSame(200);
            self::assertNotSame($etag, $client->getResponse()->headers->get('ETag'));
        } finally {
            unlink($path);
            rmdir($dir);
        }
    }

    public function testMissingInvalidAndGuestAvatarsShareTheSameError(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $player = TestUserFactory::create($em, 'avatar', 'player', ['ROLE_PLAYER']);
        $guest = TestUserFactory::create($em, 'avatar', 'guest', [])->setIsGuest(true);
        $admin = TestUserFactory::create($em, 'avatar', 'admin', ['ROLE_ADMIN']);
        $em->flush();
        $dir = sys_get_temp_dir().'/darts-avatar-'.bin2hex(random_bytes(8));
        mkdir($dir);
        $_ENV['AVATAR_DIR'] = $_SERVER['AVATAR_DIR'] = $dir;
        $path = $dir.'/'.strtolower((string) $player->getEmail()).'.png';
        $client->loginUser($admin);
        try {
            $url = '/api/player/'.$player->getId().'/avatar';
            $client->request('GET', $url);
            self::assertResponseStatusCodeSame(404);
            $body = $client->getResponse()->getContent();
            $image = imagecreatetruecolor(2, 2);
            self::assertNotFalse($image);
            foreach (['imagegif', 'imagejpeg'] as $write) {
                $write($image, $path);
                $client->request('GET', $url);
                self::assertResponseStatusCodeSame(404);
                self::assertSame($body, $client->getResponse()->getContent());
            }
            $client->request('GET', '/api/player/'.$guest->getId().'/avatar');
            self::assertResponseStatusCodeSame(404);
            self::assertSame($body, $client->getResponse()->getContent());
        } finally {
            unlink($path);
            rmdir($dir);
        }
    }

    public function testInvalidPngFallsBackToJpeg(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $player = TestUserFactory::create($em, 'avatar', 'player', ['ROLE_PLAYER']);
        $em->flush();
        $dir = sys_get_temp_dir().'/darts-avatar-'.bin2hex(random_bytes(8));
        mkdir($dir);
        $_ENV['AVATAR_DIR'] = $_SERVER['AVATAR_DIR'] = $dir;
        $base = $dir.'/'.strtolower((string) $player->getEmail());
        $image = imagecreatetruecolor(2, 2);
        self::assertNotFalse($image);
        imagegif($image, $base.'.png');
        imagejpeg($image, $base.'.jpg');
        $client->loginUser($player);
        try {
            $client->request('GET', '/api/player/'.$player->getId().'/avatar');
            self::assertResponseStatusCodeSame(200);
            self::assertSame('image/jpeg', $client->getResponse()->headers->get('Content-Type'));
            self::assertSame(file_get_contents($base.'.jpg'), $client->getResponse()->getContent());
        } finally {
            unlink($base.'.png');
            unlink($base.'.jpg');
            rmdir($dir);
        }
    }

    public function testMissingDirectoryReturns503(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $player = TestUserFactory::create($em, 'avatar', 'player', ['ROLE_PLAYER']);
        $admin = TestUserFactory::create($em, 'avatar', 'admin', ['ROLE_ADMIN']);
        $em->flush();
        $_ENV['AVATAR_DIR'] = $_SERVER['AVATAR_DIR'] = sys_get_temp_dir().'/missing-avatar-'.bin2hex(random_bytes(8));
        $client->loginUser($admin);
        try {
            $client->request('GET', '/api/player/'.$player->getId().'/avatar');
            self::assertResponseStatusCodeSame(503);
            self::assertStringContainsString('AVATAR_DIRECTORY_UNAVAILABLE', (string) $client->getResponse()->getContent());
        } finally {
        }
    }

    public function testDirectoryWithoutSearchPermissionReturns503(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $player = TestUserFactory::create($em, 'avatar', 'player', ['ROLE_PLAYER']);
        $admin = TestUserFactory::create($em, 'avatar', 'admin', ['ROLE_ADMIN']);
        $em->flush();
        $dir = sys_get_temp_dir().'/darts-avatar-'.bin2hex(random_bytes(8));
        mkdir($dir);
        chmod($dir, 0444);
        clearstatcache(true, $dir);
        try {
            if (is_executable($dir)) {
                self::markTestSkipped('The process can search a directory without execute permission.');
            }
            self::assertTrue(is_readable($dir));
            $_ENV['AVATAR_DIR'] = $_SERVER['AVATAR_DIR'] = $dir;
            $client->loginUser($admin);
            $client->request('GET', '/api/player/'.$player->getId().'/avatar');
            self::assertResponseStatusCodeSame(503);
            self::assertStringContainsString('AVATAR_DIRECTORY_UNAVAILABLE', (string) $client->getResponse()->getContent());
        } finally {
            chmod($dir, 0700);
            rmdir($dir);
        }
    }

    public function testPlayerCannotReadAnotherPlayersAvatarEvenWithEtag(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $player = TestUserFactory::create($em, 'avatar', 'player', ['ROLE_PLAYER']);
        $other = TestUserFactory::create($em, 'avatar', 'other', ['ROLE_PLAYER']);
        $admin = TestUserFactory::create($em, 'avatar', 'admin', ['ROLE_ADMIN']);
        $em->flush();
        $dir = sys_get_temp_dir().'/darts-avatar-'.bin2hex(random_bytes(8));
        mkdir($dir);
        $_ENV['AVATAR_DIR'] = $_SERVER['AVATAR_DIR'] = $dir;
        $path = $dir.'/'.strtolower((string) $other->getEmail()).'.png';
        $image = imagecreatetruecolor(2, 2);
        self::assertNotFalse($image);
        imagepng($image, $path);
        try {
            $url = '/api/player/'.$other->getId().'/avatar';
            $client->loginUser($admin);
            $client->request('GET', $url);
            self::assertResponseStatusCodeSame(200);
            $etag = $client->getResponse()->headers->get('ETag');
            $client->request('GET', '/api/player/999999999/avatar');
            self::assertResponseStatusCodeSame(404);
            $missingBody = $client->getResponse()->getContent();
            $client->loginUser($player);
            $client->request('GET', $url);
            self::assertResponseStatusCodeSame(404);
            self::assertSame($missingBody, $client->getResponse()->getContent());
            $client->request('GET', $url, server: ['HTTP_IF_NONE_MATCH' => $etag]);
            self::assertResponseStatusCodeSame(404);
            self::assertSame($missingBody, $client->getResponse()->getContent());
        } finally {
            unlink($path);
            rmdir($dir);
        }
    }

    public function testPngWinsAndUnsafeEmailCannotEscapeDirectory(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $player = TestUserFactory::create($em, 'avatar', 'player', ['ROLE_PLAYER']);
        $unsafe = TestUserFactory::create($em, 'avatar', 'unsafe', ['ROLE_PLAYER'])->setEmail('../outside');
        $admin = TestUserFactory::create($em, 'avatar', 'admin', ['ROLE_ADMIN']);
        $em->flush();
        $dir = sys_get_temp_dir().'/darts-avatar-'.bin2hex(random_bytes(8));
        mkdir($dir);
        $_ENV['AVATAR_DIR'] = $_SERVER['AVATAR_DIR'] = $dir;
        $base = $dir.'/'.strtolower((string) $player->getEmail());
        $image = imagecreatetruecolor(2, 2);
        self::assertNotFalse($image);
        imagepng($image, $base.'.png');
        imagejpeg($image, $base.'.jpg');
        $client->loginUser($admin);
        try {
            $client->request('GET', '/api/player/'.$player->getId().'/avatar');
            self::assertResponseStatusCodeSame(200);
            self::assertSame(file_get_contents($base.'.png'), $client->getResponse()->getContent());
            $client->request('GET', '/api/player/'.$unsafe->getId().'/avatar');
            self::assertResponseStatusCodeSame(404);
        } finally {
            unlink($base.'.png');
            unlink($base.'.jpg');
            rmdir($dir);
        }
    }

    public function testAnonymousRequestGets401(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/player/1/avatar');
        self::assertResponseStatusCodeSame(401);
    }

    public function testPlayerReceivesOwnPng(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $player = TestUserFactory::create($em, 'avatar', 'player', ['ROLE_PLAYER']);
        $em->flush();
        $dir = sys_get_temp_dir().'/darts-avatar-'.bin2hex(random_bytes(8));
        mkdir($dir);
        $_ENV['AVATAR_DIR'] = $_SERVER['AVATAR_DIR'] = $dir;
        $image = imagecreatetruecolor(2, 2);
        self::assertNotFalse($image);
        $path = $dir.'/'.strtolower((string) $player->getEmail()).'.png';
        imagepng($image, $path);
        $png = file_get_contents($path);
        $client->loginUser($player);
        try {
            $client->request('GET', '/api/player/'.$player->getId().'/avatar');
            self::assertResponseStatusCodeSame(200);
            self::assertSame($png, $client->getResponse()->getContent());
            self::assertSame('image/png', $client->getResponse()->headers->get('Content-Type'));
        } finally {
            unlink($path);
            rmdir($dir);
        }
    }
}
