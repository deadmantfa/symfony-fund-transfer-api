<?php

declare(strict_types=1);

use App\Application\Shared\IdempotencyService;
use App\Application\Transfer\DuplicateIdempotencyKeyException;
use App\Application\Transfer\InitiateTransferCommand;
use App\Application\Transfer\InitiateTransferHandler;
use App\Application\Transfer\ProcessTransferCommand;
use App\Domain\Account\Account;
use App\Domain\Account\AccountId;
use App\Domain\Account\AccountRepository;
use App\Domain\Shared\ValueObject\CurrencyCode;
use App\Domain\Transfer\TransferId;
use App\Domain\Transfer\TransferRepository;
use App\Domain\Transfer\TransferStatus;
use App\Domain\User\User;
use App\Domain\User\UserId;
use App\Domain\User\UserRepository;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

function makeVerifiedUser(): User
{
    $user = User::register(UserId::generate(), 'a@b.com', 'A', 'B', 'hash');
    $user->verifyEmail();
    $user->approveKyc();
    return $user;
}

function makeActiveAccount(UserId $userId, string $currency = 'USD'): Account
{
    return Account::open(AccountId::generate(), $userId, new CurrencyCode($currency), 'Test');
}

test('initiates transfer and returns pending transfer', function (): void {
    $userId   = UserId::generate();
    $user     = makeVerifiedUser();
    $fromAcct = makeActiveAccount($userId);
    $toAcct   = makeActiveAccount(UserId::generate());

    /** @var UserRepository $userRepo */
    $userRepo = $this->createStub(UserRepository::class);
    $userRepo->method('findById')->willReturn($user);

    /** @var AccountRepository $accountRepo */
    $accountRepo = $this->createStub(AccountRepository::class);
    $accountRepo->method('findById')->willReturnCallback(
        fn (AccountId $id) => $fromAcct->id->equals($id) ? $fromAcct : $toAcct
    );

    /** @var TransferRepository&\PHPUnit\Framework\MockObject\MockObject $transferRepo */
    $transferRepo = $this->createMock(TransferRepository::class);
    $transferRepo->method('findByIdempotencyKey')->willReturn(null);
    $transferRepo->method('existsDuplicate')->willReturn(false);
    $transferRepo->method('nextIdentity')->willReturn(TransferId::generate());
    $transferRepo->expects($this->once())->method('save');

    $idempotency = new IdempotencyService(new ArrayAdapter(), 86400);

    /** @var MessageBusInterface&\PHPUnit\Framework\MockObject\MockObject $bus */
    $bus = $this->createMock(MessageBusInterface::class);
    $bus->expects($this->once())
        ->method('dispatch')
        ->willReturn(new Envelope(new ProcessTransferCommand('id')));

    $handler  = new InitiateTransferHandler($userRepo, $accountRepo, $transferRepo, $idempotency, $bus);
    $transfer = $handler(new InitiateTransferCommand(
        userId: (string) $userId,
        fromAccountId: (string) $fromAcct->id,
        toAccountId: (string) $toAcct->id,
        amount: '50.00',
        currency: 'USD',
        idempotencyKey: 'test-key-' . uniqid(),
        ipAddress: '127.0.0.1',
        userAgent: 'test',
    ));

    expect($transfer->status)->toBe(TransferStatus::Pending);
});

test('throws DuplicateIdempotencyKeyException for duplicate key', function (): void {
    $idempotency = new IdempotencyService(new ArrayAdapter(), 86400);
    $userId      = (string) UserId::generate();
    $key         = 'dup-key';
    $idempotency->store("{$userId}.{$key}", ['transfer_id' => 'existing-id', 'status' => 'pending']);

    /** @var UserRepository $userRepo */
    $userRepo = $this->createStub(UserRepository::class);
    /** @var AccountRepository $accountRepo */
    $accountRepo = $this->createStub(AccountRepository::class);
    /** @var TransferRepository $transferRepo */
    $transferRepo = $this->createStub(TransferRepository::class);
    /** @var MessageBusInterface $bus */
    $bus = $this->createStub(MessageBusInterface::class);

    $handler = new InitiateTransferHandler($userRepo, $accountRepo, $transferRepo, $idempotency, $bus);

    expect(fn () => $handler(new InitiateTransferCommand(
        userId: $userId,
        fromAccountId: (string) AccountId::generate(),
        toAccountId: (string) AccountId::generate(),
        amount: '10.00',
        currency: 'USD',
        idempotencyKey: $key,
        ipAddress: '127.0.0.1',
        userAgent: 'test',
    )))->toThrow(DuplicateIdempotencyKeyException::class);
});

test('blocks transfer if KYC not verified', function (): void {
    $user = User::register(UserId::generate(), 'a@b.com', 'A', 'B', 'hash');

    /** @var UserRepository $userRepo */
    $userRepo = $this->createStub(UserRepository::class);
    $userRepo->method('findById')->willReturn($user);

    /** @var AccountRepository $accountRepo */
    $accountRepo = $this->createStub(AccountRepository::class);
    /** @var TransferRepository $transferRepo */
    $transferRepo = $this->createStub(TransferRepository::class);
    $idempotency  = new IdempotencyService(new ArrayAdapter(), 86400);
    /** @var MessageBusInterface $bus */
    $bus = $this->createStub(MessageBusInterface::class);

    $handler = new InitiateTransferHandler($userRepo, $accountRepo, $transferRepo, $idempotency, $bus);

    expect(fn () => $handler(new InitiateTransferCommand(
        userId: (string) $user->id,
        fromAccountId: (string) AccountId::generate(),
        toAccountId: (string) AccountId::generate(),
        amount: '10.00',
        currency: 'USD',
        idempotencyKey: 'k1',
        ipAddress: '127.0.0.1',
        userAgent: 'test',
    )))->toThrow(\DomainException::class, 'KYC');
});

test('blocks transfer to same account', function (): void {
    $userId = UserId::generate();
    $user   = makeVerifiedUser();
    $acct   = makeActiveAccount($userId);

    /** @var UserRepository $userRepo */
    $userRepo = $this->createStub(UserRepository::class);
    $userRepo->method('findById')->willReturn($user);

    /** @var AccountRepository $accountRepo */
    $accountRepo = $this->createStub(AccountRepository::class);
    $accountRepo->method('findById')->willReturn($acct);

    /** @var TransferRepository $transferRepo */
    $transferRepo = $this->createStub(TransferRepository::class);
    $transferRepo->method('nextIdentity')->willReturn(TransferId::generate());

    $idempotency = new IdempotencyService(new ArrayAdapter(), 86400);

    /** @var MessageBusInterface $bus */
    $bus = $this->createStub(MessageBusInterface::class);

    $handler = new InitiateTransferHandler($userRepo, $accountRepo, $transferRepo, $idempotency, $bus);

    expect(fn () => $handler(new InitiateTransferCommand(
        userId: (string) $userId,
        fromAccountId: (string) $acct->id,
        toAccountId: (string) $acct->id,   // same account
        amount: '10.00',
        currency: 'USD',
        idempotencyKey: 'self-' . uniqid(),
        ipAddress: '127.0.0.1',
        userAgent: 'test',
    )))->toThrow(\DomainException::class, 'same account');
});

test('blocks transfer with zero or negative amount', function (): void {
    $userId   = UserId::generate();
    $user     = makeVerifiedUser();
    $fromAcct = makeActiveAccount($userId);
    $toAcct   = makeActiveAccount(UserId::generate());

    /** @var UserRepository $userRepo */
    $userRepo = $this->createStub(UserRepository::class);
    $userRepo->method('findById')->willReturn($user);

    /** @var AccountRepository $accountRepo */
    $accountRepo = $this->createStub(AccountRepository::class);
    $accountRepo->method('findById')->willReturnCallback(
        fn (AccountId $id) => $fromAcct->id->equals($id) ? $fromAcct : $toAcct
    );

    /** @var TransferRepository $transferRepo */
    $transferRepo = $this->createStub(TransferRepository::class);
    $transferRepo->method('nextIdentity')->willReturn(TransferId::generate());

    $idempotency = new IdempotencyService(new ArrayAdapter(), 86400);

    /** @var MessageBusInterface $bus */
    $bus = $this->createStub(MessageBusInterface::class);

    $handler = new InitiateTransferHandler($userRepo, $accountRepo, $transferRepo, $idempotency, $bus);

    expect(fn () => $handler(new InitiateTransferCommand(
        userId: (string) $userId,
        fromAccountId: (string) $fromAcct->id,
        toAccountId: (string) $toAcct->id,
        amount: '0.00',
        currency: 'USD',
        idempotencyKey: 'zero-' . uniqid(),
        ipAddress: '127.0.0.1',
        userAgent: 'test',
    )))->toThrow(\DomainException::class, 'positive');
});
