<?php

namespace App\Application\Repository\Redis\Game;

use App\Game\Entity\Game;
use App\Game\Entity\Player;
use App\Game\Repository\GameRepositoryInterface;
use App\Core\Exception\DomainLogicalException;
use App\Core\Exception\NotFoundException;
use App\Redis\RedisClient;
use App\Redis\RedisRepository;

class RedisGameRepository extends RedisRepository implements GameRepositoryInterface
{
    public function __construct(private RedisClient $redis, int $dbIndex = 0)
    {
        parent::__construct($redis, $dbIndex);
    }

    public function save(Game $game): void
    {
        $gameId = $game->getId();
        $this->redis->set("game_session:$gameId", $game->toJson());
    }

    public function find(string $id): ?Game
    {
        $data = $this->redis->get("game_session:$id");

        return $data ? Game::fromArray(json_decode($data, true)) : null;
    }

    public function addPlayer(string $gameId, string $playerToken): Game
    {
        $key = "game_session:$gameId";

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->redis->getClient()->watch($key);
            $data = $this->redis->get($key);

            if (!$data) {
                $this->redis->getClient()->unwatch();
                throw new NotFoundException('Game not found.');
            }

            $game = Game::fromArray(json_decode($data, true));

            if ($game->hasPlayer($playerToken)) {
                $this->redis->getClient()->unwatch();
                throw new DomainLogicalException('Player already joined this game.');
            }

            if (count($game->getPlayers()) >= $game->getMaxPlayers()) {
                $this->redis->getClient()->unwatch();
                throw new DomainLogicalException('Game is full.');
            }

            $game->setPlayerByKey($playerToken, new Player($playerToken));
            $this->redis->getClient()->multi();
            $this->redis->getClient()->set($key, $game->toJson());

            if ($this->redis->getClient()->exec() !== false) {
                return $game;
            }
        }

        throw new DomainLogicalException('Game was changed concurrently. Please retry.');
    }

    public function removePlayer(string $gameId, string $playerToken): Game
    {
        $key = "game_session:$gameId";

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->redis->getClient()->watch($key);
            $data = $this->redis->get($key);

            if (!$data) {
                $this->redis->getClient()->unwatch();
                throw new NotFoundException('Game not found.');
            }

            $game = Game::fromArray(json_decode($data, true));

            if (!$game->hasPlayer($playerToken)) {
                $this->redis->getClient()->unwatch();
                throw new DomainLogicalException('Player is not in this game.');
            }

            $game->removePlayerById($playerToken);
            $this->redis->getClient()->multi();
            $this->redis->getClient()->set($key, $game->toJson());

            if ($this->redis->getClient()->exec() !== false) {
                return $game;
            }
        }

        throw new DomainLogicalException('Game was changed concurrently. Please retry.');
    }

    public function findAvailable(?string $summaryType = null): array
    {
        $keys = $this->redis->keys('game_session:*') ?: [];
        $games = [];

        foreach ($keys as $key) {
            $data = $this->redis->get($key);
            if (!$data) {
                continue;
            }

            $game = Game::fromArray(json_decode($data, true));

            if ($summaryType !== null && $game->getSummaryType() !== $summaryType) {
                continue;
            }

            if (count($game->getPlayers()) >= $game->getMaxPlayers()) {
                continue;
            }

            $games[] = $game;
        }

        return $games;
    }

    public function delete(string $id): void
    {
        $this->redis->del("game_session:$id");
    }
}
