<?php

namespace App\EventSubscriber;

use App\Core\Exception\DomainLogicalException;
use App\Core\Exception\InvalidDataException;
use App\Core\Exception\NotFoundException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

class ApiExceptionSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => 'onKernelException',
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        $exception = $event->getThrowable();
        [$status, $code, $title, $detail] = match (true) {
            $exception instanceof InvalidDataException => [
                400,
                'INVALID_REQUEST',
                'Invalid request',
                $exception->getMessage(),
            ],
            $exception instanceof NotFoundException => [
                404,
                'RESOURCE_NOT_FOUND',
                'Resource not found',
                $exception->getMessage(),
            ],
            $exception instanceof DomainLogicalException => [
                409,
                'BUSINESS_RULE_CONFLICT',
                'Request conflicts with the current state',
                $exception->getMessage(),
            ],
            $exception instanceof HttpExceptionInterface => [
                $exception->getStatusCode(),
                'HTTP_ERROR',
                'HTTP request failed',
                $exception->getMessage(),
            ],
            default => [
                500,
                'INTERNAL_SERVER_ERROR',
                'Internal server error',
                'An unexpected error occurred.',
            ],
        };

        $event->setResponse(new JsonResponse([
            'type' => 'about:blank',
            'title' => $title,
            'status' => $status,
            'code' => $code,
            'detail' => $detail,
        ], $status, [
            'Content-Type' => 'application/problem+json',
        ]));
    }
}