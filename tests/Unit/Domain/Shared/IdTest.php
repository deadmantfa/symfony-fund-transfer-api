<?php

declare(strict_types=1);

use App\Domain\Account\AccountId;
use App\Domain\AuditLog\AuditLogId;
use App\Domain\FxRate\FxRateId;
use App\Domain\LedgerEntry\LedgerEntryId;
use App\Domain\Transfer\TransferId;

// AccountId

test('accountId equals is case insensitive (uppercase left)', function (): void {
    $a = new AccountId('550E8400-E29B-41D4-A716-446655440000'); // uppercase on $this
    $b = new AccountId('550e8400-e29b-41d4-a716-446655440000'); // lowercase on $other
    expect($a->equals($b))->toBeTrue();
});

test('accountId equals is case insensitive (lowercase left)', function (): void {
    $a = new AccountId('550e8400-e29b-41d4-a716-446655440000');
    $b = new AccountId('550E8400-E29B-41D4-A716-446655440000');
    expect($a->equals($b))->toBeTrue();
});

test('accountId equals returns false for different ids', function (): void {
    expect(AccountId::generate()->equals(AccountId::generate()))->toBeFalse();
});

// AuditLogId

test('auditLogId equals is case insensitive (uppercase left)', function (): void {
    $a = new AuditLogId('550E8400-E29B-41D4-A716-446655440000');
    $b = new AuditLogId('550e8400-e29b-41d4-a716-446655440000');
    expect($a->equals($b))->toBeTrue();
});

test('auditLogId equals is case insensitive (lowercase left)', function (): void {
    $a = new AuditLogId('550e8400-e29b-41d4-a716-446655440000');
    $b = new AuditLogId('550E8400-E29B-41D4-A716-446655440000');
    expect($a->equals($b))->toBeTrue();
});

test('auditLogId equals returns false for different ids', function (): void {
    expect(AuditLogId::generate()->equals(AuditLogId::generate()))->toBeFalse();
});

// FxRateId

test('fxRateId equals is case insensitive (uppercase left)', function (): void {
    $a = new FxRateId('550E8400-E29B-41D4-A716-446655440000');
    $b = new FxRateId('550e8400-e29b-41d4-a716-446655440000');
    expect($a->equals($b))->toBeTrue();
});

test('fxRateId equals is case insensitive (lowercase left)', function (): void {
    $a = new FxRateId('550e8400-e29b-41d4-a716-446655440000');
    $b = new FxRateId('550E8400-E29B-41D4-A716-446655440000');
    expect($a->equals($b))->toBeTrue();
});

test('fxRateId equals returns false for different ids', function (): void {
    expect(FxRateId::generate()->equals(FxRateId::generate()))->toBeFalse();
});

// LedgerEntryId

test('ledgerEntryId equals is case insensitive (uppercase left)', function (): void {
    $a = new LedgerEntryId('550E8400-E29B-41D4-A716-446655440000');
    $b = new LedgerEntryId('550e8400-e29b-41d4-a716-446655440000');
    expect($a->equals($b))->toBeTrue();
});

test('ledgerEntryId equals is case insensitive (lowercase left)', function (): void {
    $a = new LedgerEntryId('550e8400-e29b-41d4-a716-446655440000');
    $b = new LedgerEntryId('550E8400-E29B-41D4-A716-446655440000');
    expect($a->equals($b))->toBeTrue();
});

test('ledgerEntryId equals returns false for different ids', function (): void {
    expect(LedgerEntryId::generate()->equals(LedgerEntryId::generate()))->toBeFalse();
});

// TransferId

test('transferId equals is case insensitive (uppercase left)', function (): void {
    $a = new TransferId('550E8400-E29B-41D4-A716-446655440000');
    $b = new TransferId('550e8400-e29b-41d4-a716-446655440000');
    expect($a->equals($b))->toBeTrue();
});

test('transferId equals is case insensitive (lowercase left)', function (): void {
    $a = new TransferId('550e8400-e29b-41d4-a716-446655440000');
    $b = new TransferId('550E8400-E29B-41D4-A716-446655440000');
    expect($a->equals($b))->toBeTrue();
});

test('transferId equals returns false for different ids', function (): void {
    expect(TransferId::generate()->equals(TransferId::generate()))->toBeFalse();
});
