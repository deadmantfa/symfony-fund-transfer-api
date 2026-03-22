<?php

declare(strict_types=1);

use App\Domain\Account\AccountId;
use App\Domain\Shared\ValueObject\CurrencyCode;
use App\Domain\Shared\ValueObject\Money;
use App\Domain\Transfer\Transfer;
use App\Domain\Transfer\TransferId;
use App\Domain\Transfer\TransferStatus;
use App\Domain\User\UserId;

function makeTransfer(): Transfer
{
    return Transfer::initiate(
        TransferId::generate(),
        AccountId::generate(),
        AccountId::generate(),
        new Money('100.00', new CurrencyCode('USD')),
        'key-' . uniqid(),
        UserId::generate(),
        '127.0.0.1',
        'test'
    );
}

test(
    'transfer created with pending status',
    fn () => expect(makeTransfer()->status)->toBe(TransferStatus::Pending)
);
test('pending transitions to processing', function (): void {
    $t = makeTransfer();
    $t->markProcessing();
    expect($t->status)->toBe(TransferStatus::Processing);
});
test('processing transitions to completed', function (): void {
    $t = makeTransfer();
    $t->markProcessing();
    $t->complete(new Money('100.00', new CurrencyCode('USD')), new Money('91.50', new CurrencyCode('EUR')), '0.915', 'stub');
    expect($t->status)->toBe(TransferStatus::Completed);
});
test('pending cannot go directly to completed', function (): void {
    expect(fn () => makeTransfer()->complete(
        new Money('100.00', new CurrencyCode('USD')),
        new Money('91.50', new CurrencyCode('EUR')),
        '0.915',
        'stub'
    ))->toThrow(DomainException::class);
});
test('transfer can fail with reason', function (): void {
    $t = makeTransfer();
    $t->markProcessing();
    $t->fail('insufficient_funds');
    expect($t->status)->toBe(TransferStatus::Failed)
        ->and($t->failureReason)->toBe('insufficient_funds');
});
test('pending transfer can be cancelled', function (): void {
    $t = makeTransfer();
    $t->cancel();
    expect($t->status)->toBe(TransferStatus::Failed)
        ->and($t->failureReason)->toBe('cancelled_by_user');
});
test('completed transfer cannot be cancelled', function (): void {
    $t = makeTransfer();
    $t->markProcessing();
    $t->complete(new Money('100.00', new CurrencyCode('USD')), new Money('91.50', new CurrencyCode('EUR')), '0.915', 'stub');
    expect(fn () => $t->cancel())->toThrow(DomainException::class);
});

test('completed transfer cannot be marked processing', function (): void {
    $t = makeTransfer();
    $t->markProcessing();
    $t->complete(new Money('100.00', new CurrencyCode('USD')), new Money('91.50', new CurrencyCode('EUR')), '0.915', 'stub');
    expect(fn () => $t->markProcessing())->toThrow(DomainException::class);
});

test('completed transfer cannot fail', function (): void {
    $t = makeTransfer();
    $t->markProcessing();
    $t->complete(new Money('100.00', new CurrencyCode('USD')), new Money('91.50', new CurrencyCode('EUR')), '0.915', 'stub');
    expect(fn () => $t->fail('some_reason'))->toThrow(DomainException::class);
});

test('complete stores fromAmount toAmount fxRate and fxRateProvider', function (): void {
    $t = makeTransfer();
    $t->markProcessing();
    $from = new Money('100.00', new CurrencyCode('USD'));
    $to   = new Money('91.50', new CurrencyCode('EUR'));
    $t->complete($from, $to, '0.915', 'stub-provider');
    expect($t->fromAmount?->amount->toFloat())->toBe(100.0)
        ->and($t->toAmount?->currency->value)->toBe('EUR')
        ->and($t->fxRate)->toBe('0.915')
        ->and($t->fxRateProvider)->toBe('stub-provider');
});

test('fromRow reconstructs pending transfer without amounts', function (): void {
    $id        = (string) TransferId::generate();
    $fromAccId = (string) AccountId::generate();
    $toAccId   = (string) AccountId::generate();
    $userId    = (string) UserId::generate();
    $now       = (new DateTimeImmutable())->format('Y-m-d H:i:s');

    // Omit optional nullable fields entirely (isset() returns false for missing keys, not null)
    $row = [
        'id'                   => $id,
        'from_account_id'      => $fromAccId,
        'to_account_id'        => $toAccId,
        'amount'               => '100.00',
        'currency'             => 'USD',
        'idempotency_key'      => 'key-123',
        'initiated_by_user_id' => $userId,
        'ip_address'           => '127.0.0.1',
        'user_agent'           => 'test-agent',
        'status'               => 'pending',
        'created_at'           => $now,
        'updated_at'           => $now,
    ];
    $transfer = Transfer::fromRow($row);
    expect((string) $transfer->id)->toBe($id)
        ->and($transfer->status)->toBe(TransferStatus::Pending)
        ->and($transfer->fromAmount)->toBeNull()
        ->and($transfer->failureReason)->toBeNull()
        ->and($transfer->ipAddress)->toBe('127.0.0.1');
});

test('fromRow reconstructs completed transfer with amounts', function (): void {
    $id        = (string) TransferId::generate();
    $fromAccId = (string) AccountId::generate();
    $toAccId   = (string) AccountId::generate();
    $userId    = (string) UserId::generate();
    $now       = (new DateTimeImmutable())->format('Y-m-d H:i:s');

    $row = [
        'id'                   => $id,
        'from_account_id'      => $fromAccId,
        'to_account_id'        => $toAccId,
        'amount'               => '100.00',
        'currency'             => 'USD',
        'idempotency_key'      => 'key-456',
        'initiated_by_user_id' => $userId,
        'ip_address'           => '10.0.0.1',
        'user_agent'           => 'browser',
        'status'               => 'completed',
        'from_amount'          => '100.00',
        'from_currency'        => 'USD',
        'to_amount'            => '91.50',
        'to_currency'          => 'EUR',
        'fx_rate'              => '0.915',
        'fx_rate_provider'     => 'stub',
        'created_at'           => $now,
        'updated_at'           => $now,
    ];
    $transfer = Transfer::fromRow($row);
    expect($transfer->status)->toBe(TransferStatus::Completed)
        ->and($transfer->fromAmount?->amount->toFloat())->toBe(100.0)
        ->and($transfer->toAmount?->currency->value)->toBe('EUR')
        ->and($transfer->fxRate)->toBe('0.915')
        ->and($transfer->fxRateProvider)->toBe('stub');
});

test('fromRow accepts numeric amount values as from database', function (): void {
    $id        = (string) TransferId::generate();
    $now       = (new DateTimeImmutable())->format('Y-m-d H:i:s');

    // PostgreSQL may return numeric columns as floats/ints
    $row = [
        'id'                   => $id,
        'from_account_id'      => (string) AccountId::generate(),
        'to_account_id'        => (string) AccountId::generate(),
        'amount'               => 100.00, // numeric, not string
        'currency'             => 'USD',
        'idempotency_key'      => 'key-num',
        'initiated_by_user_id' => (string) UserId::generate(),
        'ip_address'           => '192.168.1.1',
        'user_agent'           => 'agent',
        'status'               => 'completed',
        'from_amount'          => 100.00, // numeric
        'from_currency'        => 'USD',
        'to_amount'            => 91.50,  // numeric
        'to_currency'          => 'EUR',
        'fx_rate'              => '0.915',
        'fx_rate_provider'     => 'stub',
        'created_at'           => $now,
        'updated_at'           => $now,
    ];
    $transfer = Transfer::fromRow($row);
    expect($transfer->amount->amount->toFloat())->toBe(100.0)
        ->and($transfer->fromAmount?->amount->toFloat())->toBe(100.0)
        ->and($transfer->toAmount?->amount->toFloat())->toBe(91.5);
});

test('fromRow reconstructs failed transfer with failure reason', function (): void {
    $id        = (string) TransferId::generate();
    $now       = (new DateTimeImmutable())->format('Y-m-d H:i:s');

    $row = [
        'id'                   => $id,
        'from_account_id'      => (string) AccountId::generate(),
        'to_account_id'        => (string) AccountId::generate(),
        'amount'               => '50.00',
        'currency'             => 'USD',
        'idempotency_key'      => 'key-fail',
        'initiated_by_user_id' => (string) UserId::generate(),
        'status'               => 'failed',
        'failure_reason'       => 'insufficient_funds',
        'created_at'           => $now,
        'updated_at'           => $now,
    ];
    $transfer = Transfer::fromRow($row);
    expect($transfer->status)->toBe(TransferStatus::Failed)
        ->and($transfer->failureReason)->toBe('insufficient_funds')
        ->and($transfer->ipAddress)->toBe('')   // defaults to empty string when absent
        ->and($transfer->userAgent)->toBe('');
});

test('fromRow rejects non-string required fields', function (string $field, mixed $badValue): void {
    $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
    $row = [
        'id'                   => (string) TransferId::generate(),
        'from_account_id'      => (string) AccountId::generate(),
        'to_account_id'        => (string) AccountId::generate(),
        'amount'               => '100.00',
        'currency'             => 'USD',
        'idempotency_key'      => 'key',
        'initiated_by_user_id' => (string) UserId::generate(),
        'status'               => 'pending',
        'created_at'           => $now,
        'updated_at'           => $now,
    ];
    $row[$field] = $badValue;
    expect(fn () => Transfer::fromRow($row))->toThrow(AssertionError::class);
})->with([
    'non-string id'                   => ['id', []],
    'non-string from_account_id'      => ['from_account_id', []],
    'non-string to_account_id'        => ['to_account_id', []],
    'non-string currency'             => ['currency', []],
    'non-string idempotency_key'      => ['idempotency_key', []],
    'non-string initiated_by_user_id' => ['initiated_by_user_id', []],
    'non-string status'               => ['status', []],
    'non-string created_at'           => ['created_at', []],
    'non-string updated_at'           => ['updated_at', []],
]);

test('fromRow rejects non-string optional string fields when present', function (string $field, mixed $badValue): void {
    $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
    $row = [
        'id'                   => (string) TransferId::generate(),
        'from_account_id'      => (string) AccountId::generate(),
        'to_account_id'        => (string) AccountId::generate(),
        'amount'               => '100.00',
        'currency'             => 'USD',
        'idempotency_key'      => 'key',
        'initiated_by_user_id' => (string) UserId::generate(),
        'status'               => 'completed',
        'created_at'           => $now,
        'updated_at'           => $now,
        'from_amount'          => '100.00',
        'from_currency'        => 'USD',
        'to_amount'            => '91.00',
        'to_currency'          => 'EUR',
        'fx_rate'              => '0.91',
        'fx_rate_provider'     => 'stub',
        'failure_reason'       => 'none',
        'ip_address'           => '127.0.0.1',
        'user_agent'           => 'test',
    ];
    $row[$field] = $badValue;
    expect(fn () => Transfer::fromRow($row))->toThrow(AssertionError::class);
})->with([
    'non-string from_currency'   => ['from_currency', []],
    'non-string fx_rate'         => ['fx_rate', []],
    'non-string fx_rate_provider' => ['fx_rate_provider', []],
    'non-string failure_reason'  => ['failure_reason', []],
    'non-string ip_address'      => ['ip_address', []],
    'non-string user_agent'      => ['user_agent', []],
]);
