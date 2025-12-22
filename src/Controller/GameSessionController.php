<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class GameSessionController extends BaseController
{
    #[Route('/game/session/create')]
    public function create(): Response
    {
        return new JsonResponse('OK');
    }
}
