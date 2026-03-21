<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Normalizes all exceptions to RFC 9457 Problem Details format.
 * Content-Type: application/problem+json
 * Never exposes stack traces.
 * Priority -1: runs after Symfony Security's exception listener (priority 0),
 * which handles AccessDeniedException/AuthenticationException and stops event
 * propagation, so those never reach this listener.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: -1)]
final class ExceptionListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        $request   = $event->getRequest();

        // Only handle API routes
        if (!str_starts_with($request->getPathInfo(), '/api') && $request->getPathInfo() !== '/health') {
            return;
        }

        $statusCode = $exception instanceof HttpExceptionInterface
            ? $exception->getStatusCode()
            : 500;

        $problem = [
            'type'   => $this->mapType($statusCode),
            'title'  => $this->mapTitle($statusCode, $exception),
            'status' => $statusCode,
        ];

        if ($statusCode >= 400 && $statusCode < 500 && $exception->getMessage()) {
            $problem['detail'] = $exception->getMessage();
        }

        if ($statusCode >= 500) {
            $problem['detail'] = 'An internal error occurred.';
        }

        $response = new JsonResponse($problem, $statusCode);
        $response->headers->set('Content-Type', 'application/problem+json');

        $event->setResponse($response);
    }

    private function mapType(int $status): string
    {
        return match ($status) {
            400 => 'https://tools.ietf.org/html/rfc9110#section-15.5.1',
            401 => 'https://tools.ietf.org/html/rfc9110#section-15.5.2',
            403 => 'https://tools.ietf.org/html/rfc9110#section-15.5.4',
            404 => 'https://tools.ietf.org/html/rfc9110#section-15.5.5',
            409 => 'https://tools.ietf.org/html/rfc9110#section-15.5.10',
            422 => 'https://tools.ietf.org/html/rfc9110#section-15.5.21',
            429 => 'https://tools.ietf.org/html/rfc6585#section-4',
            default => 'https://tools.ietf.org/html/rfc9110#section-15.6.1',
        };
    }

    private function mapTitle(int $status, \Throwable $e): string
    {
        return match ($status) {
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            409 => 'Conflict',
            422 => 'Unprocessable Content',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            default => $e->getMessage() ?: 'Error',
        };
    }
}
