<?php

namespace App\Controller;

use App\Core\Exception\DomainLogicalException;
use App\Core\Exception\InvalidDataException;
use App\Core\Exception\NotFoundException;
use App\Game\Enum\GameType;
use App\Game\Service\GameServiceInterface;
use App\Redis\RedisClientInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class GameSessionController extends BaseController
{
    private const MIN_TIME_SECONDS = 15;
    private const MAX_TIME_SECONDS = 300;
    private const DEFAULT_TIME_SECONDS = 60;

    public function __construct(
        private GameServiceInterface $gameService,
        private RedisClientInterface $redis,
    ) {
    }

    #[Route('/api/games', methods: ['POST'])]
    public function createGame(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            throw new InvalidDataException('Request body must be a valid JSON object.');
        }

        $gameType = $data['gameType'] ?? null;
        $maxPlayers = $data['maxPlayers'] ?? null;
        $durationSeconds = $data['durationSeconds'] ?? self::DEFAULT_TIME_SECONDS;

        if (!is_string($gameType) || GameType::tryFrom($gameType) === null) {
            throw new InvalidDataException('Valid gameType is required.');
        }

        if (!is_int($maxPlayers) || $maxPlayers <= 0) {
            throw new InvalidDataException('maxPlayers must be a positive integer.');
        }

        if (!is_int($durationSeconds) || $durationSeconds < self::MIN_TIME_SECONDS || $durationSeconds > self::MAX_TIME_SECONDS) {
            throw new InvalidDataException('durationSeconds must be an integer between ' . self::MIN_TIME_SECONDS . ' and ' . self::MAX_TIME_SECONDS . '.');
        }

        $game = $this->gameService->createGame(
            uniqid(more_entropy: true),
            $gameType,
            $maxPlayers,
            $durationSeconds,
        );

        return new JsonResponse([
            'gameId' => $game->getId(),
            'gameType' => $game->getSummaryType(),
            'maxPlayers' => $game->getMaxPlayers(),
            'durationSeconds' => $game->getDurationSeconds(),
            'currentPlayers' => 0,
            'freeSlots' => $game->getMaxPlayers(),
        ], Response::HTTP_CREATED);
    }

    #[Route('/api/games/{id}/session', methods: ['POST'])]
    public function prepareSession(string $id): JsonResponse
    {
        $game = $this->gameService->findGameById($id);

        if ($game === null) {
            throw new NotFoundException('Game not found.');
        }

        if (count($game->getPlayers()) > $game->getMaxPlayers()) {
            throw new DomainLogicalException('Game has too many players.');
        }

        $sessionId = uniqid('session_', true);

        return new JsonResponse([
            'gameId' => $game->getId(),
            'sessionId' => $sessionId,
            'maxPlayers' => $game->getMaxPlayers(),
            'durationSeconds' => $game->getDurationSeconds(),
        ], Response::HTTP_CREATED);
    }

    #[Route('/api/games/{id}/session', methods: ['GET'])]
    public function getSession(string $id): JsonResponse
    {
        $game = $this->gameService->findGameById($id);

        if ($game === null) {
            throw new NotFoundException('Game not found.');
        }

        foreach ($this->redis->keys('session:*') ?: [] as $key) {
            $sessionData = json_decode($this->redis->get($key), true);
            if (!is_array($sessionData) || ($sessionData['processId'] ?? null) !== $id) {
                continue;
            }

            $connections = $sessionData['connections'] ?? [];
            if (count($connections) >= (int) ($sessionData['countOfConnections'] ?? 0)) {
                continue;
            }

            return new JsonResponse([
                'gameId' => $id,
                'sessionId' => $sessionData['id'],
                'maxPlayers' => $game->getMaxPlayers(),
                'durationSeconds' => $game->getDurationSeconds(),
            ]);
        }

        throw new NotFoundException('No active room found for this game.');
    }

    #[Route('/api/games', methods: ['GET'])]
    public function getAvailableGames(Request $request): JsonResponse
    {
        $gameType = $request->query->get('gameType');

        if ($gameType !== null && !GameType::tryFrom($gameType)) {
            throw new InvalidDataException('Invalid game type provided');
        }

        $games = $this->gameService->getAvailableGames($gameType);

        return new JsonResponse([
            'games' => array_map(static fn ($game) => [
                'id' => $game->getId(),
                'gameType' => $game->getSummaryType(),
                'maxPlayers' => $game->getMaxPlayers(),
                'durationSeconds' => $game->getDurationSeconds(),
                'currentPlayers' => count($game->getPlayers()),
                'freeSlots' => $game->getMaxPlayers() - count($game->getPlayers()),
            ], $games),
        ]);
    }

    #[Route('/api/games/{id}', methods: ['GET'])]
    public function getGameById(string $id): JsonResponse
    {
        $game = $this->gameService->findGameById($id);

        if ($game === null) {
            throw new NotFoundException('Game not found.');
        }

        return new JsonResponse([
            'id' => $game->getId(),
            'gameType' => $game->getSummaryType(),
            'maxPlayers' => $game->getMaxPlayers(),
            'durationSeconds' => $game->getDurationSeconds(),
            'currentPlayers' => count($game->getPlayers()),
            'freeSlots' => $game->getMaxPlayers() - count($game->getPlayers()),
        ]);
    }

    #[Route('/api/games/{id}/results', methods: ['GET'])]
    public function getGameResults(string $id): JsonResponse
    {
        $raw = $this->redis->get("game_result:$id");

        if ($raw === false || $raw === null || $raw === '') {
            throw new NotFoundException('Game results are not ready yet.');
        }

        $results = json_decode($raw, true);

        return new JsonResponse([
            'gameId' => $id,
            'results' => is_array($results) ? $results : [],
        ]);
    }

    #[Route('/api/games/{id}/players', methods: ['POST'])]
    public function addPlayer(string $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $playerToken = $data['playerToken'] ?? null;

        if (!is_string($playerToken) || trim($playerToken) === '') {
            throw new InvalidDataException('playerToken is required.');
        }

        $game = $this->gameService->addPlayer($id, $playerToken);

        return new JsonResponse([
            'gameId' => $game->getId(),
            'playerToken' => $playerToken,
            'currentPlayers' => count($game->getPlayers()),
            'freeSlots' => $game->getMaxPlayers() - count($game->getPlayers()),
        ], Response::HTTP_OK);
    }

    #[Route('/api/games/{id}/players', methods: ['DELETE'])]
    public function removePlayer(string $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $playerToken = $data['playerToken'] ?? null;

        if (!is_string($playerToken) || trim($playerToken) === '') {
            throw new InvalidDataException('playerToken is required.');
        }

        $game = $this->gameService->removePlayer($id, $playerToken);

        return new JsonResponse([
            'gameId' => $game->getId(),
            'playerToken' => $playerToken,
            'currentPlayers' => count($game->getPlayers()),
            'freeSlots' => $game->getMaxPlayers() - count($game->getPlayers()),
        ], Response::HTTP_OK);
    }
}
