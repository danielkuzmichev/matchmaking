<?php

namespace App\Controller;

use App\Core\Exception\DomainLogicalException;
use App\Core\Exception\InvalidDataException;
use App\Core\Exception\NotFoundException;
use App\Game\Enum\GameType;
use App\Game\Service\GameServiceInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class GameSessionController extends BaseController
{
    public function __construct(
        private GameServiceInterface $gameService,
    ) {
    }

    #[Route('/api/games', methods: ['POST'])]
    public function createGame(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $gameType = $data['gameType'] ?? null;
        $maxPlayers = $data['maxPlayers'] ?? null;

        if (!is_string($gameType) || GameType::tryFrom($gameType) === null) {
            throw new InvalidDataException('Valid gameType is required.');
        }

        if (!is_int($maxPlayers) || $maxPlayers <= 0) {
            throw new InvalidDataException('maxPlayers must be a positive integer.');
        }

        $game = $this->gameService->createGame(
            uniqid(more_entropy: true),
            $gameType,
            $maxPlayers,
        );

        return new JsonResponse([
            'gameId' => $game->getId(),
            'gameType' => $game->getSummaryType(),
            'maxPlayers' => $game->getMaxPlayers(),
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
        ], Response::HTTP_CREATED);
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
            'currentPlayers' => count($game->getPlayers()),
            'freeSlots' => $game->getMaxPlayers() - count($game->getPlayers()),
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

        try {
            $game = $this->gameService->addPlayer($id, $playerToken);
        } catch (NotFoundException $exception) {
            return new JsonResponse([
                'error' => $exception->getMessage(),
            ], Response::HTTP_NOT_FOUND);
        } catch (DomainLogicalException $exception) {
            return new JsonResponse([
                'error' => $exception->getMessage(),
            ], Response::HTTP_CONFLICT);
        }

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

        try {
            $game = $this->gameService->removePlayer($id, $playerToken);
        } catch (NotFoundException $exception) {
            return new JsonResponse([
                'error' => $exception->getMessage(),
            ], Response::HTTP_NOT_FOUND);
        } catch (DomainLogicalException $exception) {
            return new JsonResponse([
                'error' => $exception->getMessage(),
            ], Response::HTTP_CONFLICT);
        }

        return new JsonResponse([
            'gameId' => $game->getId(),
            'playerToken' => $playerToken,
            'currentPlayers' => count($game->getPlayers()),
            'freeSlots' => $game->getMaxPlayers() - count($game->getPlayers()),
        ], Response::HTTP_OK);
    }
}
