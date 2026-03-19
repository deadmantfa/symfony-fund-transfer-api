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
    ) {}

    public function __invoke(RegisterUserCommand $command): User
    {
        if ($this->userRepository->findByEmail($command->email) !== null) {
            throw new \DomainException("Email '{$command->email}' is already registered.");
        }

        $id = $this->userRepository->nextIdentity();

        // Create user with empty hash first to get a UserInterface for the hasher
        $user = User::register(
            id: $id,
            email: $command->email,
            firstName: $command->firstName,
            lastName: $command->lastName,
            passwordHash: '',
            phoneNumber: $command->phoneNumber,
        );

        $passwordHash = $this->passwordHasher->hashPassword(
            new SecurityUser($user),
            $command->password,
        );

        // Re-create with the actual hash
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
