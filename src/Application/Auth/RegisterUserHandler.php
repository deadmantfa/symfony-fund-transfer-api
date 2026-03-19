<?php

declare(strict_types=1);

namespace App\Application\Auth;

use App\Domain\User\User;
use App\Domain\User\UserRepository;
use App\Security\SecurityUser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RegisterUserHandler
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function __invoke(RegisterUserCommand $command): User
    {
        $id = $this->userRepository->nextIdentity();

        // Hash before the duplicate check: both the "exists" and "new" paths
        // take the same ~argon2id time, preventing email enumeration via timing.
        // SecurityUser wrapping a stub entity is needed so the correct hasher
        // (configured per SecurityUser class) is selected.
        $passwordHash = $this->passwordHasher->hashPassword(
            new SecurityUser(User::register(
                id: $id,
                email: $command->email,
                firstName: $command->firstName,
                lastName: $command->lastName,
                passwordHash: '',
                phoneNumber: $command->phoneNumber,
            )),
            $command->password,
        );

        if ($this->userRepository->findByEmail($command->email) !== null) {
            throw new \DomainException("Email '{$command->email}' is already registered.");
        }

        $user = User::register(
            id: $id,
            email: $command->email,
            firstName: $command->firstName,
            lastName: $command->lastName,
            passwordHash: $passwordHash,
            phoneNumber: $command->phoneNumber,
        );

        $this->userRepository->save($user);

        return $user;
    }
}
