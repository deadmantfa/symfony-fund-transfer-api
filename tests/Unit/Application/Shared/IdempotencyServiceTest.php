<?php

declare(strict_types=1);

use App\Application\Shared\IdempotencyService;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

test('stores and retrieves idempotency result', function (): void {
    $service = new IdempotencyService(new ArrayAdapter(), 86400);
    $service->store('key-123', ['transfer_id' => 'abc']);
    expect($service->get('key-123'))->toBe(['transfer_id' => 'abc']);
});

test('returns null for unknown key', function (): void {
    $service = new IdempotencyService(new ArrayAdapter(), 86400);
    expect($service->get('unknown'))->toBeNull();
});

test('exists returns false for missing key', function (): void {
    $service = new IdempotencyService(new ArrayAdapter(), 86400);
    expect($service->exists('missing'))->toBeFalse();
});
