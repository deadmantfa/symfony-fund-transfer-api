<?php

declare(strict_types=1);

use App\Application\Auth\RegisterUserCommand;
use App\Application\Auth\RegisterUserHandler;
use App\Domain\User\User;
use App\Domain\User\UserId;
use App\Domain\User\UserRepository;
use App\Security\SecurityUser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

test('registers a new user with hashed password', function (): void {
    /** @var UserRepository $repo */
    $repo = $this->createStub(UserRepository::class);
    $repo->method('findByEmail')->willReturn(null);
    $repo->method('nextIdentity')->willReturn(UserId::generate());

    /** @var UserRepository&\PHPUnit\Framework\MockObject\MockObject $repoWithExpect */
    $repoWithExpect = $this->createMock(UserRepository::class);
    $repoWithExpect->method('findByEmail')->willReturn(null);
    $repoWithExpect->method('nextIdentity')->willReturn(UserId::generate());
    $repoWithExpect->expects($this->once())->method('save');

    /** @var UserPasswordHasherInterface $hasher */
    $hasher = $this->createStub(UserPasswordHasherInterface::class);
    $hasher->method('hashPassword')->willReturn('hashed_password');

    $handler = new RegisterUserHandler($repoWithExpect, $hasher);
    $user = $handler(new RegisterUserCommand(
        email: 'alice@example.com',
        password: 'SecurePass123!',
        firstName: 'Alice',
        lastName: 'Smith',
    ));

    expect($user->email)->toBe('alice@example.com')
        ->and($user->passwordHash)->toBe('hashed_password');
});

test('throws on duplicate email', function (): void {
    $existingUser = User::register(
        UserId::generate(), 'alice@example.com', 'Alice', 'Smith', 'hash',
    );

    /** @var UserRepository $repo */
    $repo = $this->createStub(UserRepository::class);
    $repo->method('findByEmail')->willReturn($existingUser);
    $repo->method('nextIdentity')->willReturn(UserId::generate());

    /** @var UserPasswordHasherInterface $hasher */
    $hasher = $this->createStub(UserPasswordHasherInterface::class);

    $handler = new RegisterUserHandler($repo, $hasher);

    expect(fn () => $handler(new RegisterUserCommand('alice@example.com', 'pass', 'A', 'S')))
        ->toThrow(\DomainException::class, 'already registered');
});

test('hashed password is stored not plaintext', function (): void {
    /** @var UserRepository $repo */
    $repo = $this->createStub(UserRepository::class);
    $repo->method('findByEmail')->willReturn(null);
    $repo->method('nextIdentity')->willReturn(UserId::generate());

    /** @var UserPasswordHasherInterface $hasher */
    $hasher = $this->createStub(UserPasswordHasherInterface::class);
    $hasher->method('hashPassword')->willReturnCallback(
        fn (SecurityUser $u, string $plain): string => '$argon2id$' . base64_encode($plain),
    );

    $handler = new RegisterUserHandler($repo, $hasher);
    $user = $handler(new RegisterUserCommand('bob@example.com', 'MyPlaintext', 'Bob', 'Jones'));

    expect($user->passwordHash)->not->toBe('MyPlaintext')
        ->and($user->passwordHash)->toStartWith('$argon2id$');
});
