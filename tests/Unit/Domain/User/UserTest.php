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
    expect(fn () => $user->approveKyc())->toThrow(DomainException::class);
});

test('canInitiateTransfer requires verified KYC and email', function (): void {
    $user = User::register(UserId::generate(), 'a@b.com', 'A', 'B', 'hash');
    expect($user->canInitiateTransfer())->toBeFalse();
    $user->verifyEmail();
    expect($user->canInitiateTransfer())->toBeFalse(); // still needs KYC
    $user->approveKyc();
    expect($user->canInitiateTransfer())->toBeTrue();
});

test('suspended user cannot initiate transfer', function (): void {
    $user = User::register(UserId::generate(), 'a@b.com', 'A', 'B', 'hash');
    $user->verifyEmail();
    $user->approveKyc();
    $user->suspend();
    expect($user->canInitiateTransfer())->toBeFalse()
        ->and($user->status)->toBe(UserStatus::Suspended);
});

test('user can be closed', function (): void {
    $user = User::register(UserId::generate(), 'a@b.com', 'A', 'B', 'hash');
    $user->close();
    expect($user->status)->toBe(UserStatus::Closed);
});

test('updateProfile updates provided fields and leaves others unchanged', function (): void {
    $user = User::register(UserId::generate(), 'a@b.com', 'Alice', 'Smith', 'hash');
    $user->updateProfile(firstName: 'Bob', phoneNumber: '+1234567890');
    expect($user->firstName)->toBe('Bob')
        ->and($user->lastName)->toBe('Smith')  // unchanged
        ->and($user->phoneNumber)->toBe('+1234567890');
});

test('updateProfile with all null leaves fields unchanged', function (): void {
    $user = User::register(UserId::generate(), 'a@b.com', 'Alice', 'Smith', 'hash');
    $before = $user->firstName;
    $user->updateProfile();
    expect($user->firstName)->toBe($before);
});

test('updateProfile updates address', function (): void {
    $user    = User::register(UserId::generate(), 'a@b.com', 'A', 'B', 'hash');
    $address = ['street' => '123 Main St', 'city' => 'NYC'];
    $user->updateProfile(address: $address);
    expect($user->address)->toBe($address);
});

test('fromRow reconstructs user with optional fields', function (): void {
    $id  = (string) UserId::generate();
    $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
    $row = [
        'id'                => $id,
        'email'             => 'test@example.com',
        'first_name'        => 'John',
        'last_name'         => 'Doe',
        'password_hash'     => 'hash',
        'kyc_status'        => 'unverified',
        'status'            => 'active',
        'created_at'        => $now,
        'updated_at'        => $now,
        'phone_number'      => '+447700000000',
        'date_of_birth'     => '1990-01-15',
        'email_verified_at' => $now,
    ];
    $user = User::fromRow($row);
    expect($user->email)->toBe('test@example.com')
        ->and($user->phoneNumber)->toBe('+447700000000')
        ->and($user->emailVerifiedAt)->not->toBeNull();
});

test('canInitiateTransfer returns false when KYC verified but email not verified', function (): void {
    $user = User::register(UserId::generate(), 'a@b.com', 'A', 'B', 'hash');
    $user->approveKyc(); // KYC done but no email verification
    expect($user->canInitiateTransfer())->toBeFalse();
});

test('fromRow reconstructs user with address as JSON string', function (): void {
    $id      = (string) UserId::generate();
    $now     = (new DateTimeImmutable())->format('Y-m-d H:i:s');
    $address = ['street' => '1 Main St', 'city' => 'London'];
    $row = [
        'id'            => $id,
        'email'         => 'json@example.com',
        'first_name'    => 'Json',
        'last_name'     => 'Test',
        'password_hash' => 'hash',
        'kyc_status'    => 'unverified',
        'status'        => 'active',
        'created_at'    => $now,
        'updated_at'    => $now,
        'address'       => json_encode($address),
    ];
    $user = User::fromRow($row);
    expect($user->address)->toBe($address);
});

test('fromRow reconstructs user with address as array', function (): void {
    $id      = (string) UserId::generate();
    $now     = (new DateTimeImmutable())->format('Y-m-d H:i:s');
    $address = ['street' => '2 Oak Ave'];
    $row = [
        'id'            => $id,
        'email'         => 'arr@example.com',
        'first_name'    => 'Arr',
        'last_name'     => 'Test',
        'password_hash' => 'hash',
        'kyc_status'    => 'unverified',
        'status'        => 'active',
        'created_at'    => $now,
        'updated_at'    => $now,
        'address'       => $address,
    ];
    $user = User::fromRow($row);
    expect($user->address)->toBe($address);
});

test('fromRow reconstructs user without optional fields', function (): void {
    $id  = (string) UserId::generate();
    $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
    $row = [
        'id'            => $id,
        'email'         => 'min@example.com',
        'first_name'    => 'Min',
        'last_name'     => 'User',
        'password_hash' => 'hash',
        'kyc_status'    => 'verified',
        'status'        => 'active',
        'created_at'    => $now,
        'updated_at'    => $now,
    ];
    $user = User::fromRow($row);
    expect($user->phoneNumber)->toBeNull()
        ->and($user->dateOfBirth)->toBeNull()
        ->and($user->emailVerifiedAt)->toBeNull();
});

test('fromRow reconstructs user with terms accepted at', function (): void {
    $id  = (string) UserId::generate();
    $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
    $row = [
        'id'               => $id,
        'email'            => 'terms@example.com',
        'first_name'       => 'T',
        'last_name'        => 'U',
        'password_hash'    => 'hash',
        'kyc_status'       => 'unverified',
        'status'           => 'active',
        'created_at'       => $now,
        'updated_at'       => $now,
        'terms_accepted_at' => $now,
    ];
    $user = User::fromRow($row);
    expect($user->termsAcceptedAt)->not->toBeNull();
});

test('fromRow rejects non-string required fields', function (string $field, mixed $badValue): void {
    $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
    $row = [
        'id'            => (string) UserId::generate(),
        'email'         => 'a@b.com',
        'first_name'    => 'A',
        'last_name'     => 'B',
        'password_hash' => 'hash',
        'kyc_status'    => 'unverified',
        'status'        => 'active',
        'created_at'    => $now,
        'updated_at'    => $now,
    ];
    $row[$field] = $badValue;
    expect(fn () => User::fromRow($row))->toThrow(AssertionError::class);
})->with([
    'non-string id'            => ['id', []],
    'non-string email'         => ['email', []],
    'non-string first_name'    => ['first_name', []],
    'non-string last_name'     => ['last_name', []],
    'non-string password_hash' => ['password_hash', []],
    'non-string kyc_status'    => ['kyc_status', []],
    'non-string status'        => ['status', []],
    'non-string created_at'    => ['created_at', []],
    'non-string updated_at'    => ['updated_at', []],
]);

test('fromRow rejects non-string address when it is not null and not an array', function (): void {
    $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
    $row = [
        'id'            => (string) UserId::generate(),
        'email'         => 'a@b.com',
        'first_name'    => 'A',
        'last_name'     => 'B',
        'password_hash' => 'hash',
        'kyc_status'    => 'unverified',
        'status'        => 'active',
        'created_at'    => $now,
        'updated_at'    => $now,
        'address'       => 42, // not null, not array, not string -> triggers assert(is_string)
    ];
    expect(fn () => User::fromRow($row))->toThrow(AssertionError::class);
});

test('fromRow rejects non-string optional fields when present', function (string $field, mixed $badValue): void {
    $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
    $row = [
        'id'                 => (string) UserId::generate(),
        'email'              => 'a@b.com',
        'first_name'         => 'A',
        'last_name'          => 'B',
        'password_hash'      => 'hash',
        'kyc_status'         => 'unverified',
        'status'             => 'active',
        'created_at'         => $now,
        'updated_at'         => $now,
        'phone_number'       => '+1234567890',
        'date_of_birth'      => '1990-01-15',
        'email_verified_at'  => $now,
    ];
    $row[$field] = $badValue;
    expect(fn () => User::fromRow($row))->toThrow(AssertionError::class);
})->with([
    'non-string phone_number'      => ['phone_number', []],
    'non-string date_of_birth'     => ['date_of_birth', []],
    'non-string email_verified_at' => ['email_verified_at', []],
]);

test('userId equals is case insensitive (lowercase left)', function (): void {
    $lower = new UserId('550e8400-e29b-41d4-a716-446655440000');
    $upper = new UserId('550E8400-E29B-41D4-A716-446655440000');
    expect($lower->equals($upper))->toBeTrue();
});

test('userId equals is case insensitive (uppercase left)', function (): void {
    $upper = new UserId('550E8400-E29B-41D4-A716-446655440000');
    $lower = new UserId('550e8400-e29b-41d4-a716-446655440000');
    expect($upper->equals($lower))->toBeTrue();
});

test('userId equals returns false for different ids', function (): void {
    $a = UserId::generate();
    $b = UserId::generate();
    expect($a->equals($b))->toBeFalse();
});
