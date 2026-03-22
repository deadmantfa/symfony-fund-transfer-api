<?php

declare(strict_types=1);

use App\Application\Auth\RegisterUserCommand;
use App\Application\Auth\RegisterUserHandler;
use App\Domain\User\User;
use App\Domain\User\UserId;
use App\Domain\User\UserRepository;
use App\Security\SecurityUser;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

test('registers a new user with hashed password', function (): void {
    /** @var UserRepository $repo */
    $repo = $this->createStub(UserRepository::class);
    $repo->method('findByEmail')->willReturn(null);
    $repo->method('nextIdentity')->willReturn(UserId::generate());

    /** @var UserRepository&MockObject $repoWithExpect */
    $repoWithExpect = $this->createMock(UserRepository::class);
    $repoWithExpect->method('findByEmail')->willReturn(null);
    $repoWithExpect->method('nextIdentity')->willReturn(UserId::generate());
    $repoWithExpect->expects($this->once())->method('save');

    /** @var UserPasswordHasherInterface $hasher */
    $hasher = $this->createStub(UserPasswordHasherInterface::class);
    $hasher->method('hashPassword')->willReturn('hashed_password');

    $handler = new RegisterUserHandler($repoWithExpect, $hasher, new NullLogger());
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
        UserId::generate(),
        'alice@example.com',
        'Alice',
        'Smith',
        'hash',
    );

    /** @var UserRepository $repo */
    $repo = $this->createStub(UserRepository::class);
    $repo->method('findByEmail')->willReturn($existingUser);
    $repo->method('nextIdentity')->willReturn(UserId::generate());

    /** @var UserPasswordHasherInterface $hasher */
    $hasher = $this->createStub(UserPasswordHasherInterface::class);

    $handler = new RegisterUserHandler($repo, $hasher, new NullLogger());

    expect(fn (): User => $handler(new RegisterUserCommand('alice@example.com', 'pass', 'A', 'S')))
        ->toThrow(DomainException::class, 'already registered');
});

test('temporary stub user for hashing uses empty password hash', function (): void {
    // Kills EmptyStringToNotEmpty mutation: if '' is changed to 'x', the SecurityUser
    // stub passed to hashPassword would have passwordHash='x' not ''.
    $capturedSecurityUser = null;

    /** @var UserRepository $repo */
    $repo = $this->createStub(UserRepository::class);
    $repo->method('findByEmail')->willReturn(null);
    $repo->method('nextIdentity')->willReturn(UserId::generate());

    /** @var UserPasswordHasherInterface&MockObject $hasher */
    $hasher = $this->createMock(UserPasswordHasherInterface::class);
    $hasher->method('hashPassword')
        ->willReturnCallback(function (SecurityUser $su, string $plain) use (&$capturedSecurityUser): string {
            $capturedSecurityUser = $su;
            return 'hashed!';
        });

    $handler = new RegisterUserHandler($repo, $hasher, new NullLogger());
    $handler(new RegisterUserCommand('tmp@example.com', 'pass', 'T', 'U'));

    expect($capturedSecurityUser)->not->toBeNull();
    expect($capturedSecurityUser->user->passwordHash)->toBe('');
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

    $handler = new RegisterUserHandler($repo, $hasher, new NullLogger());
    $user = $handler(new RegisterUserCommand('bob@example.com', 'MyPlaintext', 'Bob', 'Jones'));

    expect($user->passwordHash)->not->toBe('MyPlaintext')
        ->and($user->passwordHash)->toStartWith('$argon2id$');
});
