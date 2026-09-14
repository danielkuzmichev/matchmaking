<?php

namespace App\Game\Repository;

use App\Game\Entity\Game;

interface GameRepositoryInterface
{
    public function save(Game $game): void;

    public function find(string $id): ?Game;

    public function addPlayer(string $gameId, string $playerToken): Game;

    public function removePlayer(string $gameId, string $playerToken): Game;

    public function findAvailable(?string $summaryType = null): array;

    public function delete(string $id): void;
}
