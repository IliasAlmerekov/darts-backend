<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Http;

use App\Entity\Game;
use App\Exception\Game\GameNotFoundException;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Answers a #[MapEntity] Game argument whose game does not exist with GAME_NOT_FOUND.
 * A miss on any other entity keeps the resolver's generic 404 NOT_FOUND.
 *
 * @psalm-suppress UnusedClass Registered via Symfony service container autoconfiguration (#[AsDecorator])
 */
#[AsDecorator('doctrine.orm.entity_value_resolver')]
final readonly class GameEntityValueResolver implements ValueResolverInterface
{
    /**
     * @param ValueResolverInterface $inner
     */
    public function __construct(
        #[AutowireDecorated]
        private ValueResolverInterface $inner,
    ) {
    }

    /**
     * @param Request          $request
     * @param ArgumentMetadata $argument
     *
     * @return iterable<mixed>
     *
     * @throws GameNotFoundException When the argument is a Game and no game has the id
     */
    #[Override]
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        try {
            return $this->inner->resolve($request, $argument);
        } catch (NotFoundHttpException $exception) {
            if (Game::class === $argument->getType()) {
                throw new GameNotFoundException();
            }

            throw $exception;
        }
    }
}
