<?php declare(strict_types=1);

namespace App\Service;

use App\Dto\GameResponseDto;
use App\Dto\PlayerResponseDto;
use App\Dto\ThrowResponseDto;
use App\Entity\Game;
use App\Repository\RoundRepository;
use App\Repository\RoundThrowsRepository;

/**
 * This class is responsible for creating GameResponseDto objects from Game entities.
 */
readonly class GameService
{
    public function __construct(
        private RoundRepository       $roundRepository,
        private RoundThrowsRepository $roundThrowsRepository,
    )
    {
    }


    /**
     * Calculates and returns the id of the active player for the given game.
     *
     * Logic:
     * Players will be sorted by their position in the game.
     * The player is active if:
     * - He has not yet reached a score of 0 (not won)
     * - He has thrown less than 3 times in the current round
     * - He is not bust in the current round
     *
     * @param Game $game The game entity
     * @return int|null The id of the active player or null if no active player found
     */

    public function calculateActivePlayer(Game $game): ?int
    {
        $currentRoundNumber = $game->getRound() ?? 1;

        // Hole die aktuelle Runde aus der DB
        $roundEntity = $this->roundRepository->findOneBy([
            'game' => $game,
            'roundNumber' => $currentRoundNumber
        ]);

        // Sortiere Spieler nach Position (wichtig für die Reihenfolge!)
        $gamePlayers = $game->getGamePlayers()->toArray();
        usort($gamePlayers, fn($a, $b) => $a->getPosition() <=> $b->getPosition());

        // Gehe alle Spieler der Reihe nach durch
        foreach ($gamePlayers as $gamePlayer) {
            $user = $gamePlayer->getPlayer();
            if (!$user) {
                continue; // Spieler ohne User-Entity überspringen
            }

            // Check: Hat der Spieler das Spiel schon beendet? (Score = 0)
            $playerScore = $gamePlayer->getScore() ?? $game->getStartScore();
            if ($playerScore === 0) {
                continue; // Ja → Dieser Spieler ist fertig, nächster!
            }

            // Check: Wie viele Würfe hat er in DIESER Runde?
            $throwsCount = 0;
            $hasBusted = false;

            if ($roundEntity) {
                $throws = $this->roundThrowsRepository->findBy([
                    'round' => $roundEntity,
                    'player' => $user
                ]);
                $throwsCount = count($throws);

                // Check: War sein letzter Wurf ein Bust?
                if ($throwsCount > 0) {
                    $lastThrow = end($throws);
                    $hasBusted = $lastThrow->isBust();
                }
            }

            // Entscheidung: Darf dieser Spieler werfen?
            if ($throwsCount < 3 && !$hasBusted) {
                return $user->getId(); // Dieser Spieler ist dran!
            }
        }

        // Wenn wir hier ankommen: Alle Spieler haben 3 Würfe oder sind bust.
        // Die Runde ist zu Ende, kein Spieler ist aktiv
        return null;
    }


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

        $calculatedActivePlayerId = $this->calculateActivePlayer($game);

        // DTOs für Spieler erstellen
        $playerDtos = [];
        $currentThrowCountForActivePlayer = 0;

        foreach ($gamePlayers as $gamePlayer) {
            $user = $gamePlayer->getPlayer();
            if (!$user) continue;

            $throwsThisRound = 0;
            $isBust = false;
            $currentRoundThrows = [];
            $roundHistory = [];

            if ($roundEntity) {
                $throws = $this->roundThrowsRepository->findBy([
                    'round' => $roundEntity,
                    'player' => $user
                ], ['throwNumber' => 'ASC']);

                $throwsThisRound = count($throws);

                // Baue Array mit den einzelnen Würfen
                foreach ($throws as $throw) {
                    $currentRoundThrows[] = new ThrowResponseDto(
                        value: $throw->getValue(),
                        isDouble: $throw->isDouble(),
                        isTriple: $throw->isTriple(),
                        isBust: $throw->isBust(),
                    );
                }

                // Check, ob der letzte Wurf ein Bust war
                if ($throwsThisRound > 0) {
                    $lastThrow = end($throws);
                    $isBust = $lastThrow->isBust();
                }
            }

            // Baue roundHistory: alle Runden mit Würfen für diesen Spieler
            $allRounds = $this->roundRepository->findBy(
                ['game' => $game],
                ['roundNumber' => 'ASC']
            );

            foreach ($allRounds as $round) {
                $roundThrows = $this->roundThrowsRepository->findBy([
                    'round' => $round,
                    'player' => $user
                ], ['throwNumber' => 'ASC']);

                if (count($roundThrows) > 0) {
                    $throws = array_map(
                        fn($throw) => new ThrowResponseDto(
                            value: $throw->getValue(),
                            isDouble: $throw->isDouble(),
                            isTriple: $throw->isTriple(),
                            isBust: $throw->isBust(),
                        ),
                        $roundThrows
                    );

                    $roundHistory[] = [
                        'round' => $round->getRoundNumber(),
                        'throws' => $throws
                    ];
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
                throwsInCurrentRound: $throwsThisRound,
                currentRoundThrows: $currentRoundThrows,
                roundHistory: $roundHistory
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
