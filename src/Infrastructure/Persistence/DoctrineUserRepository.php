<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\User\User;
use App\Domain\User\UserId;
use App\Domain\User\UserRepository;
use Doctrine\DBAL\Connection;

final class DoctrineUserRepository implements UserRepository
{
    public function __construct(private readonly Connection $connection) {}

    public function findById(UserId $id): ?User
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM users WHERE id = ?',
            [(string) $id],
        );

        return $row !== false ? User::fromRow($this->castRow($row)) : null;
    }

    public function findByEmail(string $email): ?User
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM users WHERE email = ?',
            [$email],
        );

        return $row !== false ? User::fromRow($this->castRow($row)) : null;
    }

    public function save(User $user): void
    {
        $this->connection->executeStatement(
            'INSERT INTO users
                (id, email, first_name, last_name, password_hash, phone_number,
                 date_of_birth, address, kyc_status, status,
                 email_verified_at, terms_accepted_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT (id) DO UPDATE SET
                email = EXCLUDED.email,
                first_name = EXCLUDED.first_name,
                last_name = EXCLUDED.last_name,
                password_hash = EXCLUDED.password_hash,
                phone_number = EXCLUDED.phone_number,
                date_of_birth = EXCLUDED.date_of_birth,
                address = EXCLUDED.address,
                kyc_status = EXCLUDED.kyc_status,
                status = EXCLUDED.status,
                email_verified_at = EXCLUDED.email_verified_at,
                terms_accepted_at = EXCLUDED.terms_accepted_at,
                updated_at = EXCLUDED.updated_at',
            [
                (string) $user->id,
                $user->email,
                $user->firstName,
                $user->lastName,
                $user->passwordHash,
                $user->phoneNumber,
                $user->dateOfBirth?->format('Y-m-d'),
                $user->address !== null ? json_encode($user->address) : null,
                $user->kycStatus->value,
                $user->status->value,
                $user->emailVerifiedAt?->format('Y-m-d H:i:s'),
                $user->termsAcceptedAt?->format('Y-m-d H:i:s'),
                $user->createdAt->format('Y-m-d H:i:s'),
                $user->updatedAt->format('Y-m-d H:i:s'),
            ],
        );
    }

    public function nextIdentity(): UserId
    {
        return UserId::generate();
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function castRow(array $row): array
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

        return $row;
    }
}
