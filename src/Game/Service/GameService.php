<?php

namespace App\Game\Service;

use App\Game\Entity\Game;
use App\Game\Repository\GameRepositoryInterface;
use App\Game\Repository\WordRepositoryInterface;

class GameService implements GameServiceInterface
{
    public function __construct(
        private GameRepositoryInterface $gameRepository,
        private WordRepositoryInterface $wordRepository,
    ) {
    }

    public function createGame(string $id, string $summaryType, int $maxPlayers): Game
    {
        $sessionWord = $this->wordRepository->getRandomSessionWord();
        $game = new Game(
            $id,
            $sessionWord,
            $summaryType,
            $maxPlayers,
            []
        );
        $this->gameRepository->save($game);

        return $game;
    }

    public function findGameById(string $id): ?Game
    {
        return $this->gameRepository->find($id);
    }

    public function addPlayer(string $gameId, string $playerToken): Game
    {
        return $this->gameRepository->addPlayer($gameId, $playerToken);
    }

    public function removePlayer(string $gameId, string $playerToken): Game
    {
        return $this->gameRepository->removePlayer($gameId, $playerToken);
    }

    public function getAvailableGames(?string $summaryType = null): array
    {
        return $this->gameRepository->findAvailable($summaryType);
    }

    public function deleteGame(string $id): void
    {
        $this->gameRepository->delete($id);
    }
}