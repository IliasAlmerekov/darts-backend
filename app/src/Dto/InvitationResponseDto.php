<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Dto;

use OpenApi\Attributes as OA;

/**
 * Invitation create response: the invitation link of a game and who is seated in it.
 *
 * @psalm-suppress PossiblyUnusedProperty Used via Symfony Serializer
 */
#[OA\Schema(required: ['success', 'gameId', 'invitationLink'])]
final class InvitationResponseDto
{
    // Declared before the constructor, so the JSON body starts with `success`.
    #[OA\Property(example: true)]
    public bool $success = true;

    /**
     * @param int                                                 $gameId
     * @param string                                              $invitationLink
     * @param array<int, array{id:int|null,username:string|null}> $users
     */
    public function __construct(
        #[OA\Property(example: 123)]
        public int $gameId,
        #[OA\Property(example: '/api/invite/join/xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx')]
        public string $invitationLink,
        #[OA\Property(
            type: 'array',
            description: 'Spieler/Benutzer, die aktuell im Spiel sind (vereinfachte Darstellung).',
            items: new OA\Items(
                type: 'object',
                properties: [
                    new OA\Property(property: 'id', type: 'integer', nullable: true, example: 1),
                    new OA\Property(property: 'username', type: 'string', nullable: true, example: 'alice'),
                ]
            )
        )]
        // The default keeps `users` out of the generated `required` list, as documented before #263.
        public array $users = [],
    ) {
    }
}
