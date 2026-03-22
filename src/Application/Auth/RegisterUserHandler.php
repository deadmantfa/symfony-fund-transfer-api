<?php

declare(strict_types=1);

namespace App\Application\Auth;

use App\Domain\User\User;
use App\Domain\User\UserRepository;
use App\Security\SecurityUser;
use DomainException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final readonly class RegisterUserHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private UserPasswordHasherInterface $passwordHasher,
        #[Autowire(service: 'monolog.logger.security')]
        private LoggerInterface $logger,
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

        if ($this->userRepository->findByEmail($command->email) instanceof User) {
            throw new DomainException("Email '{$command->email}' is already registered.");
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

        $this->logger->info('user.registered', [
            'user_id' => (string) $user->id,
        ]);

        return $user;
    }
}
