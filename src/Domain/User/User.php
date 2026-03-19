<?php

declare(strict_types=1);

namespace App\Domain\User;

use DateTimeImmutable;

final class User
{
    private function __construct(
        public private(set) readonly UserId $id,
        public private(set) string $email,
        public private(set) string $firstName,
        public private(set) string $lastName,
        public private(set) string $passwordHash,
        public private(set) ?string $phoneNumber,
        public private(set) ?DateTimeImmutable $dateOfBirth,
        public private(set) ?array $address,
        public private(set) KycStatus $kycStatus,
        public private(set) UserStatus $status,
        public private(set) ?DateTimeImmutable $emailVerifiedAt,
        public private(set) ?DateTimeImmutable $termsAcceptedAt,
        public private(set) readonly DateTimeImmutable $createdAt,
        public private(set) DateTimeImmutable $updatedAt,
    ) {}

    public static function register(
        UserId $id, string $email, string $firstName, string $lastName,
        string $passwordHash, ?string $phoneNumber = null,
        ?DateTimeImmutable $dateOfBirth = null, ?array $address = null,
    ): self {
        $now = new DateTimeImmutable();

        return new self($id, $email, $firstName, $lastName, $passwordHash,
            $phoneNumber, $dateOfBirth, $address,
            KycStatus::Unverified, UserStatus::Active, null, null, $now, $now);
    }

    public static function fromRow(array $row): self
    {
        return new self(
            id: new UserId($row['id']),
            email: $row['email'],
            firstName: $row['first_name'],
            lastName: $row['last_name'],
            passwordHash: $row['password_hash'],
            phoneNumber: $row['phone_number'] ?? null,
            dateOfBirth: isset($row['date_of_birth']) ? new DateTimeImmutable($row['date_of_birth']) : null,
            address: isset($row['address']) ? (is_array($row['address']) ? $row['address'] : json_decode($row['address'], true)) : null,
            kycStatus: KycStatus::from($row['kyc_status']),
            status: UserStatus::from($row['status']),
            emailVerifiedAt: isset($row['email_verified_at']) ? new DateTimeImmutable($row['email_verified_at']) : null,
            termsAcceptedAt: isset($row['terms_accepted_at']) ? new DateTimeImmutable($row['terms_accepted_at']) : null,
            createdAt: new DateTimeImmutable($row['created_at']),
            updatedAt: new DateTimeImmutable($row['updated_at']),
        );
    }

    public function verifyEmail(): void
    {
        $this->emailVerifiedAt = new DateTimeImmutable();
        $this->updatedAt       = new DateTimeImmutable();
    }

    public function approveKyc(): void
    {
        if (!$this->status->isActive()) {
            throw new \DomainException('Cannot approve KYC for inactive user.');
        }
        $this->kycStatus = KycStatus::Verified;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function suspend(): void
    {
        $this->status    = UserStatus::Suspended;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function close(): void
    {
        $this->status    = UserStatus::Closed;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function updateProfile(
        ?string $firstName = null, ?string $lastName = null,
        ?string $phoneNumber = null, ?array $address = null,
    ): void {
        if ($firstName !== null) {
            $this->firstName = $firstName;
        }
        if ($lastName !== null) {
            $this->lastName = $lastName;
        }
        if ($phoneNumber !== null) {
            $this->phoneNumber = $phoneNumber;
        }
        if ($address !== null) {
            $this->address = $address;
        }
        $this->updatedAt = new DateTimeImmutable();
    }

    public function canInitiateTransfer(): bool
    {
        return $this->status->isActive()
            && $this->kycStatus->allowsTransfers()
            && $this->emailVerifiedAt !== null;
    }
}
