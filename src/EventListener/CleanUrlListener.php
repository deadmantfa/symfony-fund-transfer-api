<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Rewrites /v1/* → /api/v1/* and /docs* → /api/docs* on the api.fund-transfer.local subdomain.
 *
 * This allows clean external URLs (api.fund-transfer.local/v1/transfers) without redundant
 * /api/ prefixing, while keeping all Symfony routes and tests on /api/v1/… unchanged.
 *
 * Note: Caddy rewrite directives do not propagate to FrankenPHP worker mode because the worker
 * reads the original request URI from the PHP SAPI layer. This listener is the reliable equivalent.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 1024)]
final class CleanUrlListener
{
    private const API_HOST = 'api.fund-transfer.local';

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if ($request->getHost() !== self::API_HOST) {
            return;
        }

        /** @var string $uri */
        $uri  = $request->server->get('REQUEST_URI', '/');
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path)) {
            return;
        }

        if (!str_starts_with($path, '/v1/') && $path !== '/docs' && !str_starts_with($path, '/docs/') && !str_starts_with($path, '/docs?')) {
            return;
        }

        // Prepend /api so the Symfony router sees /api/v1/… or /api/docs…
        $request->server->set('REQUEST_URI', '/api' . $uri);
    }
}
