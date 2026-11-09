<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Controller;

use App\Dto\PlayerProfileResponseDto;
use App\Dto\PlayerPublicProfileResponseDto;
use App\Entity\User;
use App\Exception\ApiHttpException;
use App\Exception\ErrorCode;
use App\Http\Attribute\ApiResponse;
use App\Repository\UserRepositoryInterface;
use App\Service\Player\PlayerProfileServiceInterface;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Player-facing Profile: the logged-in player's own statistics and recent finished games, and
 * the Public profile of any registered player (docs/adr/0010-players-read-public-profiles.md).
 *
 * @psalm-suppress UnusedClass Routed by Symfony framework
 */
#[OA\Tag(name: 'Player')]
final class PlayerProfileController extends AbstractController
{
    /**
     * Returns the logged-in player's statistics and their 10 most recent finished games.
     *
     * @param PlayerProfileServiceInterface $playerProfileService
     *
     * @return PlayerProfileResponseDto
     */
    #[OA\Response(
        response: 200,
        description: 'Profil des angemeldeten Spielers: eigene Statistik und die letzten 10 beendeten Spiele, neueste zuerst. Die Statistik zählt beendete Spiele mit mindestens einer beendeten eigenen Runde und berechnet Siege, Checkouts, Trefferverteilung, Lieblings-Doubles (nur Double-Out) und das höchste Finish aus den eigenen Würfen dieser Spiele; Prozentwerte 0–100 mit einer Nachkommastelle. Von anderen Teilnehmern nur Anzeigename, Platzierung, `playerId` (`null` für Gäste) und `isSelf`.',
        content: new OA\JsonContent(ref: new Model(type: PlayerProfileResponseDto::class))
    )]
    #[OA\Response(response: 401, description: 'Nicht angemeldet.')]
    #[OA\Response(response: 403, description: 'Angemeldet ohne `ROLE_PLAYER` (z. B. der Admin) (`ACCESS_DENIED`).', content: new OA\JsonContent(ref: '#/components/schemas/ApiError'))]
    #[ApiResponse]
    #[Route('/api/player/profile', name: 'app_player_profile', methods: ['GET'], format: 'json')]
    public function profile(PlayerProfileServiceInterface $playerProfileService): PlayerProfileResponseDto
    {
        $user = $this->getUser();
        if (!$user instanceof User || null === $user->getId()) {
            throw $this->createAccessDeniedException();
        }

        return $playerProfileService->getProfile($user);
    }

    /**
     * Returns the Public profile of a registered player: name and Profile statistics.
     *
     * @param int                           $id
     * @param UserRepositoryInterface       $users
     * @param PlayerProfileServiceInterface $playerProfileService
     *
     * @return PlayerPublicProfileResponseDto
     */
    #[OA\Response(
        response: 200,
        description: 'Öffentliches Profil eines registrierten Spielers, lesbar für jeden Spieler, auch für sich selbst: `playerId`, Anzeigename (sonst Benutzername) und dieselbe Statistik wie im eigenen Profil. Ohne Spiele ist die Statistik leer. Letzte Spiele, Registrierungsdatum und E-Mail fehlen.',
        content: new OA\JsonContent(ref: new Model(type: PlayerPublicProfileResponseDto::class))
    )]
    #[OA\Response(response: 401, description: 'Nicht angemeldet.')]
    #[OA\Response(response: 403, description: 'Angemeldet ohne `ROLE_PLAYER` (z. B. der Admin) (`ACCESS_DENIED`).', content: new OA\JsonContent(ref: '#/components/schemas/ApiError'))]
    #[OA\Response(response: 404, description: 'Kein registrierter Spieler: unbekannte ID, Gast oder Admin-Konto, alle mit derselben Antwort (`PLAYER_NOT_FOUND`).', content: new OA\JsonContent(ref: '#/components/schemas/ApiError'))]
    #[ApiResponse]
    #[Route('/api/player/{id}/profile', name: 'app_player_public_profile', requirements: ['id' => '\d+'], methods: ['GET'], format: 'json')]
    public function publicProfile(int $id, UserRepositoryInterface $users, PlayerProfileServiceInterface $playerProfileService): PlayerPublicProfileResponseDto
    {
        $player = $users->find($id);
        if (!$player instanceof User || !$player->isRegisteredPlayer()) {
            throw new ApiHttpException(ErrorCode::PlayerNotFound, Response::HTTP_NOT_FOUND, 'Player not found.');
        }

        return $playerProfileService->getPublicProfile($player);
    }
}
