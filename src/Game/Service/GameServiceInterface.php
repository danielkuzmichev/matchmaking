<?php

namespace App\Game\Service;

interface GameServiceInterface
{
    public function createGame(string $id, string $summaryType, int $maxPlayers, int $durationSeconds): mixed;

    public function findGameById(string $id): mixed;

    public function addPlayer(string $gameId, string $playerToken): mixed;

    public function removePlayer(string $gameId, string $playerToken): mixed;

    public function getAvailableGames(?string $summaryType = null): array;

    public function deleteGame(string $id): void;
}