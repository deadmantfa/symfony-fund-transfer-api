<?php

declare(strict_types=1);

use App\Domain\User\KycStatus;
use App\Domain\User\User;
use App\Domain\User\UserId;
use App\Domain\User\UserStatus;

test('user created with unverified KYC and active status', function (): void {
    $user = User::register(UserId::generate(), 'a@b.com', 'A', 'B', 'hash');
    expect($user->kycStatus)->toBe(KycStatus::Unverified)
        ->and($user->status)->toBe(UserStatus::Active)
        ->and($user->emailVerifiedAt)->toBeNull();
});

test('user can verify email', function (): void {
    $user = User::register(UserId::generate(), 'a@b.com', 'A', 'B', 'hash');
    $user->verifyEmail();
    expect($user->emailVerifiedAt)->not->toBeNull();
});

test('user KYC can be approved', function (): void {
    $user = User::register(UserId::generate(), 'a@b.com', 'A', 'B', 'hash');
    $user->approveKyc();
    expect($user->kycStatus)->toBe(KycStatus::Verified);
});

test('suspended user cannot approve KYC', function (): void {
    $user = User::register(UserId::generate(), 'a@b.com', 'A', 'B', 'hash');
    $user->suspend();
    expect(fn () => $user->approveKyc())->toThrow(\DomainException::class);
});

test('canInitiateTransfer requires verified KYC and email', function (): void {
    $user = User::register(UserId::generate(), 'a@b.com', 'A', 'B', 'hash');
    expect($user->canInitiateTransfer())->toBeFalse();
    $user->verifyEmail();
    expect($user->canInitiateTransfer())->toBeFalse(); // still needs KYC
    $user->approveKyc();
    expect($user->canInitiateTransfer())->toBeTrue();
});
