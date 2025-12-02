<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\GameResponseDto;
use App\Dto\PlayerResponseDto;
use App\Entity\Game;
use App\Repository\RoundRepository;
use App\Repository\RoundThrowsRepository;

/**
 * This class is responsible for creating GameResponseDto objects from Game entities.
 */
class GameService
{
    public function __construct(
        private readonly RoundRepository $roundRepository,
        private readonly RoundThrowsRepository $roundThrowsRepository,
    ) {}

    public function createGameDto(Game $game): GameResponseDto
    {
        // 1. Aktive Runde und Würfe ermitteln
        $currentRoundNumber = $game->getRound() ?? 1;
        $roundEntity = $this->roundRepository->findOneBy([
            'game' => $game,
            'roundNumber' => $currentRoundNumber
        ]);

        // Sortiere Spieler nach Position (Reihenfolge im Spiel)
        $gamePlayers = $game->getGamePlayers()->toArray();
        usort($gamePlayers, fn($a, $b) => $a->getPosition() <=> $b->getPosition());

        $calculatedActivePlayerId = null;

        foreach ($gamePlayers as $gamePlayer) {
            $user = $gamePlayer->getPlayer();
            if (!$user) continue;

            if (($gamePlayer->getScore() ?? $game->getStartScore()) === 0) {
                continue; // Spieler hat bereits gewonnen
            }

            $throwsCount = 0;
            $hasBusted = false;

            if ($roundEntity) {
                $throws = $this->roundThrowsRepository->findBy(['round' => $roundEntity, 'player' => $user]);
                $throwsCount = count($throws);

                // Check ob der letzte Wurf ein Bust war
                if ($throwsCount > 0) {
                    $lastThrow = end($throws);
                    $hasBusted = $lastThrow->isBust();
                }
            }

            if ($throwsCount < 3 && !$hasBusted) {
                $calculatedActivePlayerId = $user->getId();
                break;
            }
        }

        // DTOs für Spieler erstellen
        $playerDtos = [];
        $currentThrowCountForActivePlayer = 0;

        foreach ($gamePlayers as $gamePlayer) {
            $user = $gamePlayer->getPlayer();
            if (!$user) continue;

            $throwsThisRound = 0;
            $isBust = false;

            if ($roundEntity) {
                $throws = $this->roundThrowsRepository->findBy(['round' => $roundEntity, 'player' => $user]);
                $throwsThisRound = count($throws);
                if ($throwsThisRound > 0) {
                    $lastThrow = end($throws);
                    $isBust = $lastThrow->isBust();
                }
            }

            $isActive = ($user->getId() === $calculatedActivePlayerId);

            if ($isActive) {
                $currentThrowCountForActivePlayer = $throwsThisRound;
            }

            $playerDtos[] = new PlayerResponseDto(
                id: $user->getId(),
                name: $user->getUsername(),
                score: $gamePlayer->getScore() ?? $game->getStartScore(),
                isActive: $isActive,
                isBust: $isBust,
                position: $gamePlayer->getPosition(),
                throwsInCurrentRound: $throwsThisRound
            );
        }

        return new GameResponseDto(
            id: $game->getGameId(),
            status: $game->getStatus()->value,
            currentRound: $currentRoundNumber ?? 1,
            activePlayerId: $calculatedActivePlayerId,
            currentThrowCount: $currentThrowCountForActivePlayer,
            players: $playerDtos,
            winnerId: $game->getWinner()?->getId(),
            settings: [
                'startScore' => $game->getStartScore(),
                'doubleOut' => $game->isDoubleOut(),
                'tripleOut' => $game->isTripleOut(),
            ]
        );
    }
}
