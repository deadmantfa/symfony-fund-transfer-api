<?php

declare(strict_types=1);

use App\Domain\Account\AccountId;
use App\Domain\LedgerEntry\EntryType;
use App\Domain\LedgerEntry\LedgerEntry;
use App\Domain\LedgerEntry\LedgerEntryId;
use App\Domain\Shared\ValueObject\CurrencyCode;
use App\Domain\Shared\ValueObject\Money;
use App\Domain\Transfer\TransferId;

test('ledger entry records debit with balances', function (): void {
    $before = new Money('100.00', new CurrencyCode('USD'));
    $amount = new Money('40.00', new CurrencyCode('USD'));
    $after  = new Money('60.00', new CurrencyCode('USD'));
    $entry  = LedgerEntry::record(
        LedgerEntryId::generate(),
        TransferId::generate(),
        AccountId::generate(),
        EntryType::Debit,
        $amount,
        $before,
        $after,
    );
    expect($entry->entryType)->toBe(EntryType::Debit)
        ->and($entry->amount->amount->toFloat())->toBe(40.0)
        ->and($entry->balanceBefore->amount->toFloat())->toBe(100.0)
        ->and($entry->balanceAfter->amount->toFloat())->toBe(60.0);
});
