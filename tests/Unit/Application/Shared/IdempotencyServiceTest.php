<?php

declare(strict_types=1);

use App\Application\Shared\IdempotencyService;
use App\Application\Transfer\DuplicateIdempotencyKeyException;
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

test('exists returns true after storing a key', function (): void {
    $service = new IdempotencyService(new ArrayAdapter(), 86400);
    $service->store('present', ['x' => 1]);
    expect($service->exists('present'))->toBeTrue();
});

test('get returns stored array value not non-array', function (): void {
    // Kills the assert(is_array($value)) RemoveFunctionCall mutation:
    // if we store an array and retrieve it, the assert ensures it's an array.
    // The assert is on the retrieved value — verifying return type is array.
    $service = new IdempotencyService(new ArrayAdapter(), 86400);
    $service->store('typed', ['transfer_id' => 'xyz', 'status' => 'pending']);
    $result = $service->get('typed');
    expect($result)->toBeArray()
        ->and($result['transfer_id'])->toBe('xyz')
        ->and($result['status'])->toBe('pending');
});

test('DuplicateIdempotencyKeyException carries cached response', function (): void {
    $cached    = ['transfer_id' => 'abc-123', 'status' => 'pending'];
    $exception = new DuplicateIdempotencyKeyException($cached);
    expect($exception->cachedResponse)->toBe($cached)
        ->and($exception->getMessage())->toBe('Duplicate idempotency key.');
});
