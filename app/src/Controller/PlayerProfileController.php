<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Controller;

use App\Dto\PlayerProfileResponseDto;
use App\Entity\User;
use App\Http\Attribute\ApiResponse;
use App\Service\Player\PlayerProfileServiceInterface;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Player-facing Profile: the logged-in player's own statistics and recent finished games.
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
        description: 'Profil des angemeldeten Spielers: eigene Statistik und die letzten 10 beendeten Spiele, neueste zuerst. Von anderen Teilnehmern nur Anzeigename, Platzierung, `playerId` (`null` für Gäste) und `isSelf`.',
        content: new OA\JsonContent(ref: new Model(type: PlayerProfileResponseDto::class))
    )]
    #[OA\Response(response: 401, description: 'Nicht angemeldet.')]
    #[OA\Response(response: 403, description: 'Angemeldet ohne `ROLE_PLAYER` (z. B. der Admin).')]
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
}
