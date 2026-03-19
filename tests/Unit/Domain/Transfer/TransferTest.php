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
        TransferId::generate(), AccountId::generate(), AccountId::generate(),
        new Money('100.00', new CurrencyCode('USD')),
        'key-' . uniqid(), UserId::generate(), '127.0.0.1', 'test'
    );
}

test('transfer created with pending status', fn () =>
    expect(makeTransfer()->status)->toBe(TransferStatus::Pending)
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
        new Money('100.00', new CurrencyCode('USD')), new Money('91.50', new CurrencyCode('EUR')), '0.915', 'stub'
    ))->toThrow(\DomainException::class);
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
    expect(fn () => $t->cancel())->toThrow(\DomainException::class);
});
