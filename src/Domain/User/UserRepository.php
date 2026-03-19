<?php

declare(strict_types=1);

namespace App\Domain\User;

interface UserRepository
{
    public function findById(UserId $id): ?User;

    public function findByEmail(string $email): ?User;

    public function save(User $user): void;

    public function nextIdentity(): UserId;
}
