<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Ensures every request carries an X-Request-ID correlation ID.
 * Generates one if the client did not supply it, and echoes it back
 * in the response so the caller can trace their request in logs.
 */
#[AsEventListener(event: KernelEvents::REQUEST, method: 'onRequest', priority: 255)]
#[AsEventListener(event: KernelEvents::RESPONSE, method: 'onResponse', priority: -255)]
final class CorrelationIdListener
{
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$request->headers->has('X-Request-ID')) {
            $request->headers->set('X-Request-ID', $this->generateId());
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $event->getResponse()->headers->set(
            'X-Request-ID',
            $event->getRequest()->headers->get('X-Request-ID', '')
        );
    }

    private function generateId(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
        );
    }
}
