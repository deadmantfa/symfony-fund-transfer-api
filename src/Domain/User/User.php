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
        /** @var array<string, mixed>|null */
        public private(set) ?array $address,
        public private(set) KycStatus $kycStatus,
        public private(set) UserStatus $status,
        public private(set) ?DateTimeImmutable $emailVerifiedAt,
        public private(set) ?DateTimeImmutable $termsAcceptedAt,
        public private(set) readonly DateTimeImmutable $createdAt,
        public private(set) DateTimeImmutable $updatedAt,
    ) {}

    /**
     * @param array<string, mixed>|null $address
     */
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

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        assert(is_string($row['id']));
        assert(is_string($row['email']));
        assert(is_string($row['first_name']));
        assert(is_string($row['last_name']));
        assert(is_string($row['password_hash']));
        assert(is_string($row['kyc_status']));
        assert(is_string($row['status']));
        assert(is_string($row['created_at']));
        assert(is_string($row['updated_at']));

        $rawAddress = $row['address'] ?? null;
        /** @var array<string, mixed>|null $address */
        $address = null;
        if ($rawAddress !== null) {
            if (is_array($rawAddress)) {
                $address = $rawAddress;
            } else {
                assert(is_string($rawAddress));
                /** @var array<string, mixed>|null $decoded */
                $decoded = json_decode($rawAddress, true);
                $address = $decoded;
            }
        }

        $phoneNumber = null;
        if (isset($row['phone_number'])) {
            assert(is_string($row['phone_number']));
            $phoneNumber = $row['phone_number'];
        }

        $dateOfBirth = null;
        if (isset($row['date_of_birth'])) {
            assert(is_string($row['date_of_birth']));
            $dateOfBirth = new DateTimeImmutable($row['date_of_birth']);
        }

        $emailVerifiedAt = null;
        if (isset($row['email_verified_at'])) {
            assert(is_string($row['email_verified_at']));
            $emailVerifiedAt = new DateTimeImmutable($row['email_verified_at']);
        }

        $termsAcceptedAt = null;
        if (isset($row['terms_accepted_at'])) {
            assert(is_string($row['terms_accepted_at']));
            $termsAcceptedAt = new DateTimeImmutable($row['terms_accepted_at']);
        }

        return new self(
            id: new UserId($row['id']),
            email: $row['email'],
            firstName: $row['first_name'],
            lastName: $row['last_name'],
            passwordHash: $row['password_hash'],
            phoneNumber: $phoneNumber,
            dateOfBirth: $dateOfBirth,
            address: $address,
            kycStatus: KycStatus::from($row['kyc_status']),
            status: UserStatus::from($row['status']),
            emailVerifiedAt: $emailVerifiedAt,
            termsAcceptedAt: $termsAcceptedAt,
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

    /**
     * @param array<string, mixed>|null $address
     */
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
