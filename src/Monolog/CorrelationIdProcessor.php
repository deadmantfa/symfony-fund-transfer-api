<?php

declare(strict_types=1);

namespace App\Monolog;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Injects the X-Request-ID correlation ID into every log record.
 * In CLI contexts (Messenger worker) the value is 'cli'.
 */
final class CorrelationIdProcessor implements ProcessorInterface
{
    public function __construct(private readonly RequestStack $requestStack) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        $request = $this->requestStack->getCurrentRequest();
        $correlationId = $request?->headers->get('X-Request-ID') ?? 'cli';

        return $record->with(extra: [
            ...$record->extra,
            'correlation_id' => $correlationId,
        ]);
    }
}
