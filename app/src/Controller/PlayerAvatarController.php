<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Exception\ApiHttpException;
use App\Exception\ErrorCode;
use App\Repository\UserRepositoryInterface;
use App\Service\Player\AvatarStoreInterface;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;

/** @psalm-suppress UnusedClass Routed by Symfony. */
#[OA\Tag(name: 'Player')]
final class PlayerAvatarController extends AbstractController
{
    #[OA\Response(response: 200, description: 'Avatar image. Cache-Control is private, no-cache; ETag identifies the bytes.', content: [new OA\MediaType(mediaType: 'image/png'), new OA\MediaType(mediaType: 'image/jpeg')])]
    #[OA\Parameter(name: 'If-None-Match', in: 'header', required: false, schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 304, description: 'Avatar unchanged.')]
    #[OA\Response(response: 401, description: 'Authentication required.')]
    #[OA\Response(response: 404, description: 'Avatar not found.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError'))]
    #[OA\Response(response: 503, description: 'Avatar directory unavailable.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError'))]
    #[Route('/api/player/{id}/avatar', name: 'app_player_avatar', requirements: ['id' => '\d+'], methods: ['GET'])]
    /**
     * @param int                     $id
     * @param Request                 $request
     * @param UserRepositoryInterface $users
     * @param AvatarStoreInterface    $avatars
     *
     * @return Response
     */
    public function avatar(int $id, Request $request, UserRepositoryInterface $users, AvatarStoreInterface $avatars): Response
    {
        $caller = $this->getUser();
        $player = $users->find($id);
        if (!$caller instanceof User || !$player instanceof User || $player->isGuest()
            || (!$this->isGranted('ROLE_ADMIN') && $caller->getId() !== $id)) {
            throw new ApiHttpException(ErrorCode::PlayerNotFound, Response::HTTP_NOT_FOUND, 'Avatar not found.');
        }
        $avatar = $avatars->find($player->getEmail() ?? '');
        if (null === $avatar) {
            throw new ApiHttpException(ErrorCode::PlayerNotFound, Response::HTTP_NOT_FOUND, 'Avatar not found.');
        }
        $response = new Response($avatar['bytes']);
        $response->headers->set('Content-Type', $avatar['contentType']);
        $response->headers->set('Cache-Control', 'private, no-cache');
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, '1');
        $response->setEtag(hash('sha256', $avatar['bytes']));
        $response->isNotModified($request);

        return $response;
    }
}
