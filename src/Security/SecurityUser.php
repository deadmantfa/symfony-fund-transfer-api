<?php

declare(strict_types=1);

namespace App\Security;

use App\Domain\User\User;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class SecurityUser implements UserInterface, PasswordAuthenticatedUserInterface
{
    public function __construct(public readonly User $user)
    {
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function getPassword(): string
    {
        return $this->user->passwordHash;
    }

    public function getUserIdentifier(): string
    {
        return $this->user->email !== '' ? $this->user->email : throw new \LogicException('User email is empty.');
    }

    #[\Deprecated]
    public function eraseCredentials(): void
    {
    }
}
