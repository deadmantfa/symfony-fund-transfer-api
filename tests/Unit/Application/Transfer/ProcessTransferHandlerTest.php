<?php

declare(strict_types=1);

use App\Application\Transfer\ProcessTransferCommand;
use App\Application\Transfer\ProcessTransferHandler;
use App\Domain\Account\Account;
use App\Domain\Account\AccountId;
use App\Domain\Account\AccountRepository;
use App\Domain\AuditLog\AuditLog;
use App\Domain\AuditLog\AuditLogId;
use App\Domain\AuditLog\AuditLogRepository;
use App\Domain\FxRate\FxRate;
use App\Domain\FxRate\FxRateId;
use App\Domain\FxRate\FxRateRepository;
use App\Domain\LedgerEntry\LedgerEntryId;
use App\Domain\LedgerEntry\LedgerEntryRepository;
use App\Domain\Shared\ValueObject\CurrencyCode;
use App\Domain\Shared\ValueObject\Money;
use App\Domain\Transfer\Transfer;
use App\Domain\Transfer\TransferId;
use App\Domain\Transfer\TransferRepository;
use App\Domain\Transfer\TransferStatus;
use App\Domain\User\UserId;
use App\Infrastructure\FxRate\CachingFxRateService;
use App\Infrastructure\FxRate\StubFxRateProvider;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

function makePendingTransfer(string $fromId, string $toId, string $amount = '50.00'): Transfer
{
    return Transfer::initiate(
        id: TransferId::generate(),
        fromAccountId: new AccountId($fromId),
        toAccountId: new AccountId($toId),
        amount: new Money($amount, new CurrencyCode('USD')),
        idempotencyKey: 'k-' . uniqid(),
        initiatedByUserId: UserId::generate(),
        ipAddress: '127.0.0.1',
        userAgent: 'test',
    );
}

function makeCachingFxService(): CachingFxRateService
{
    /** @var FxRateRepository $fxRepo */
    $fxRepo = (new class () implements FxRateRepository {
        public function findLatest(CurrencyCode $from, CurrencyCode $to): ?FxRate
        {
            return null;
        }
        public function save(FxRate $rate): void
        {
        }
        public function nextIdentity(): FxRateId
        {
            return FxRateId::generate();
        }
    });

    return new CachingFxRateService(
        new StubFxRateProvider(),
        new ArrayAdapter(),
        $fxRepo,
        new NullLogger(),
        300,
    );
}

test('processes transfer successfully: debits source, credits destination, marks completed', function (): void {
    $fromIdStr = (string) AccountId::generate();
    $toIdStr   = (string) AccountId::generate();

    $transfer = makePendingTransfer($fromIdStr, $toIdStr, '50.00');

    $fromAccount = Account::open(new AccountId($fromIdStr), UserId::generate(), new CurrencyCode('USD'), 'From');
    // Manually credit balance to 200 via reflection-free approach: debit 0 won't work, need real money
    // Use a Transfer approach: credit via public method
    $fromAccount->credit(new Money('200.00', new CurrencyCode('USD')));

    $toAccount = Account::open(new AccountId($toIdStr), UserId::generate(), new CurrencyCode('USD'), 'To');

    /** @var Connection&MockObject $conn */
    $conn = $this->createMock(Connection::class);
    $conn->expects($this->once())->method('beginTransaction');
    $conn->expects($this->once())->method('commit');
    $conn->expects($this->never())->method('rollBack');

    /** @var TransferRepository&MockObject $transferRepo */
    $transferRepo = $this->createMock(TransferRepository::class);
    $transferRepo->method('findByIdForUpdate')->willReturn($transfer);
    $transferRepo->expects($this->exactly(2))->method('save');

    /** @var AccountRepository&MockObject $accountRepo */
    $accountRepo = $this->createMock(AccountRepository::class);
    $accountRepo->method('findByIdForUpdate')->willReturnCallback(
        fn (AccountId $id): Account => (string) $id === $fromIdStr ? $fromAccount : $toAccount
    );
    $accountRepo->expects($this->exactly(2))->method('save');

    /** @var LedgerEntryRepository&MockObject $ledgerRepo */
    $ledgerRepo = $this->createMock(LedgerEntryRepository::class);
    $ledgerRepo->method('nextIdentity')->willReturn(LedgerEntryId::generate());
    $ledgerRepo->expects($this->exactly(2))->method('append');

    /** @var AuditLogRepository&MockObject $auditRepo */
    $auditRepo = $this->createMock(AuditLogRepository::class);
    $auditRepo->method('nextIdentity')->willReturn(AuditLogId::generate());
    $auditRepo->expects($this->once())->method('append');

    $handler = new ProcessTransferHandler($conn, $transferRepo, $accountRepo, $ledgerRepo, $auditRepo, makeCachingFxService(), new NullLogger());
    $handler(new ProcessTransferCommand((string) $transfer->id));

    expect($transfer->status)->toBe(TransferStatus::Completed);
    // Same currency (USD->USD), rate is 1, debit 50, credit 50
    expect((string) $fromAccount->balance->amount)->toBe('150.00000000');
    // multiply with rate 1.0000000000 gives 10 decimal places via BigDecimal
    expect($toAccount->balance->amount->toFloat())->toBe(50.0);
});

test('audit log on success contains status and fx_rate', function (): void {
    $fromIdStr = (string) AccountId::generate();
    $toIdStr   = (string) AccountId::generate();
    $transfer  = makePendingTransfer($fromIdStr, $toIdStr, '50.00');

    $fromAccount = Account::open(new AccountId($fromIdStr), UserId::generate(), new CurrencyCode('USD'), 'From');
    $fromAccount->credit(new Money('200.00', new CurrencyCode('USD')));
    $toAccount = Account::open(new AccountId($toIdStr), UserId::generate(), new CurrencyCode('USD'), 'To');

    /** @var Connection&MockObject $conn */
    $conn = $this->createMock(Connection::class);
    $conn->method('beginTransaction');
    $conn->method('commit');

    /** @var TransferRepository&MockObject $transferRepo */
    $transferRepo = $this->createMock(TransferRepository::class);
    $transferRepo->method('findByIdForUpdate')->willReturn($transfer);
    $transferRepo->method('save');

    /** @var AccountRepository&MockObject $accountRepo */
    $accountRepo = $this->createMock(AccountRepository::class);
    $accountRepo->method('findByIdForUpdate')->willReturnCallback(
        fn (AccountId $id): Account => (string) $id === $fromIdStr ? $fromAccount : $toAccount
    );
    $accountRepo->method('save');

    /** @var LedgerEntryRepository&MockObject $ledgerRepo */
    $ledgerRepo = $this->createMock(LedgerEntryRepository::class);
    $ledgerRepo->method('nextIdentity')->willReturn(LedgerEntryId::generate());
    $ledgerRepo->method('append');

    /** @var AuditLogRepository&MockObject $auditRepo */
    $auditRepo = $this->createMock(AuditLogRepository::class);
    $auditRepo->method('nextIdentity')->willReturn(AuditLogId::generate());
    $auditRepo->expects($this->once())->method('append')
        ->with($this->callback(function (AuditLog $log): bool {
            $data = $log->newValue;
            return isset($data['status'], $data['fx_rate'])
                && $data['status'] === 'completed';
        }));

    $handler = new ProcessTransferHandler($conn, $transferRepo, $accountRepo, $ledgerRepo, $auditRepo, makeCachingFxService(), new NullLogger());
    $handler(new ProcessTransferCommand((string) $transfer->id));
});

test('processes cross-currency transfer: credit destination in target currency', function (): void {
    $fromIdStr = (string) AccountId::generate();
    $toIdStr   = (string) AccountId::generate();

    $transfer = makePendingTransfer($fromIdStr, $toIdStr, '100.00'); // USD amount

    $fromAccount = Account::open(new AccountId($fromIdStr), UserId::generate(), new CurrencyCode('USD'), 'From USD');
    $fromAccount->credit(new Money('200.00', new CurrencyCode('USD')));

    $toAccount = Account::open(new AccountId($toIdStr), UserId::generate(), new CurrencyCode('EUR'), 'To EUR');

    /** @var Connection&MockObject $conn */
    $conn = $this->createMock(Connection::class);
    $conn->expects($this->once())->method('beginTransaction');
    $conn->expects($this->once())->method('commit');

    /** @var TransferRepository&MockObject $transferRepo */
    $transferRepo = $this->createMock(TransferRepository::class);
    $transferRepo->method('findByIdForUpdate')->willReturn($transfer);
    $transferRepo->expects($this->exactly(2))->method('save');

    /** @var AccountRepository&MockObject $accountRepo */
    $accountRepo = $this->createMock(AccountRepository::class);
    $accountRepo->method('findByIdForUpdate')->willReturnCallback(
        fn (AccountId $id): Account => (string) $id === $fromIdStr ? $fromAccount : $toAccount
    );
    $accountRepo->expects($this->exactly(2))->method('save');

    /** @var LedgerEntryRepository&MockObject $ledgerRepo */
    $ledgerRepo = $this->createMock(LedgerEntryRepository::class);
    $ledgerRepo->method('nextIdentity')->willReturn(LedgerEntryId::generate());
    $ledgerRepo->expects($this->exactly(2))->method('append');

    /** @var AuditLogRepository&MockObject $auditRepo */
    $auditRepo = $this->createMock(AuditLogRepository::class);
    $auditRepo->method('nextIdentity')->willReturn(AuditLogId::generate());
    $auditRepo->expects($this->once())->method('append');

    $handler = new ProcessTransferHandler($conn, $transferRepo, $accountRepo, $ledgerRepo, $auditRepo, makeCachingFxService(), new NullLogger());
    $handler(new ProcessTransferCommand((string) $transfer->id));

    expect($transfer->status)->toBe(TransferStatus::Completed);
    // Destination balance must be in EUR (not USD)
    expect($toAccount->balance->currency->value)->toBe('EUR');
    expect($toAccount->balance->amount->isGreaterThan(BigDecimal::zero()))->toBeTrue();
});

test('throws when transfer not found', function (): void {
    /** @var Connection&MockObject $conn */
    $conn = $this->createMock(Connection::class);
    $conn->expects($this->once())->method('beginTransaction');
    $conn->expects($this->never())->method('commit');

    /** @var TransferRepository $transferRepo */
    $transferRepo = $this->createStub(TransferRepository::class);
    $transferRepo->method('findByIdForUpdate')->willReturn(null);

    /** @var AccountRepository $accountRepo */
    $accountRepo = $this->createStub(AccountRepository::class);
    /** @var LedgerEntryRepository $ledgerRepo */
    $ledgerRepo = $this->createStub(LedgerEntryRepository::class);
    /** @var AuditLogRepository $auditRepo */
    $auditRepo = $this->createStub(AuditLogRepository::class);

    $handler = new ProcessTransferHandler($conn, $transferRepo, $accountRepo, $ledgerRepo, $auditRepo, makeCachingFxService(), new NullLogger());
    expect(fn () => $handler(new ProcessTransferCommand((string) AccountId::generate())))
        ->toThrow(RuntimeException::class, 'not found');
});

test('re-throws non-domain exceptions and rolls back transaction', function (): void {
    $fromIdStr = (string) AccountId::generate();
    $toIdStr   = (string) AccountId::generate();
    $transfer  = makePendingTransfer($fromIdStr, $toIdStr, '50.00');

    /** @var Connection&MockObject $conn */
    $conn = $this->createMock(Connection::class);
    $conn->expects($this->once())->method('beginTransaction');
    $conn->expects($this->once())->method('rollBack');
    $conn->expects($this->never())->method('commit');

    /** @var TransferRepository&MockObject $transferRepo */
    $transferRepo = $this->createMock(TransferRepository::class);
    $transferRepo->method('findByIdForUpdate')->willThrowException(new RuntimeException('DB error'));

    /** @var AccountRepository $accountRepo */
    $accountRepo = $this->createStub(AccountRepository::class);
    /** @var LedgerEntryRepository $ledgerRepo */
    $ledgerRepo = $this->createStub(LedgerEntryRepository::class);
    /** @var AuditLogRepository $auditRepo */
    $auditRepo = $this->createStub(AuditLogRepository::class);

    $handler = new ProcessTransferHandler($conn, $transferRepo, $accountRepo, $ledgerRepo, $auditRepo, makeCachingFxService(), new NullLogger());
    expect(fn () => $handler(new ProcessTransferCommand((string) $transfer->id)))
        ->toThrow(RuntimeException::class, 'DB error');
});

test('fails transfer with account_frozen when source account is frozen', function (): void {
    $fromIdStr = (string) AccountId::generate();
    $toIdStr   = (string) AccountId::generate();
    $transfer  = makePendingTransfer($fromIdStr, $toIdStr, '50.00');

    $fromAccount = Account::open(new AccountId($fromIdStr), UserId::generate(), new CurrencyCode('USD'), 'From');
    $fromAccount->credit(new Money('200.00', new CurrencyCode('USD')));
    $fromAccount->freeze(); // account is frozen

    $toAccount = Account::open(new AccountId($toIdStr), UserId::generate(), new CurrencyCode('USD'), 'To');

    /** @var Connection&MockObject $conn */
    $conn = $this->createMock(Connection::class);
    $conn->expects($this->once())->method('beginTransaction');
    $conn->expects($this->once())->method('rollBack');

    /** @var TransferRepository&MockObject $transferRepo */
    $transferRepo = $this->createMock(TransferRepository::class);
    $transferRepo->method('findByIdForUpdate')->willReturn($transfer);
    $transferRepo->expects($this->exactly(2))->method('save');

    /** @var AccountRepository&MockObject $accountRepo */
    $accountRepo = $this->createMock(AccountRepository::class);
    $accountRepo->method('findByIdForUpdate')->willReturnCallback(
        fn (AccountId $id): Account => (string) $id === $fromIdStr ? $fromAccount : $toAccount
    );

    /** @var LedgerEntryRepository $ledgerRepo */
    $ledgerRepo = $this->createStub(LedgerEntryRepository::class);
    $ledgerRepo->method('nextIdentity')->willReturn(LedgerEntryId::generate());

    /** @var AuditLogRepository&MockObject $auditRepo */
    $auditRepo = $this->createMock(AuditLogRepository::class);
    $auditRepo->method('nextIdentity')->willReturn(AuditLogId::generate());
    $auditRepo->expects($this->once())->method('append');

    $handler = new ProcessTransferHandler($conn, $transferRepo, $accountRepo, $ledgerRepo, $auditRepo, makeCachingFxService(), new NullLogger());
    $handler(new ProcessTransferCommand((string) $transfer->id));

    expect($transfer->status)->toBe(TransferStatus::Failed)
        ->and($transfer->failureReason)->toBe('account_frozen');
});

test('fails transfer with account_closed when source account is closed', function (): void {
    $fromIdStr = (string) AccountId::generate();
    $toIdStr   = (string) AccountId::generate();
    $transfer  = makePendingTransfer($fromIdStr, $toIdStr, '50.00');

    $fromAccount = Account::open(new AccountId($fromIdStr), UserId::generate(), new CurrencyCode('USD'), 'From');
    $fromAccount->close(); // account is closed (zero balance — no credit needed)

    $toAccount = Account::open(new AccountId($toIdStr), UserId::generate(), new CurrencyCode('USD'), 'To');

    /** @var Connection&MockObject $conn */
    $conn = $this->createMock(Connection::class);
    $conn->expects($this->once())->method('beginTransaction');
    $conn->expects($this->once())->method('rollBack');

    /** @var TransferRepository&MockObject $transferRepo */
    $transferRepo = $this->createMock(TransferRepository::class);
    $transferRepo->method('findByIdForUpdate')->willReturn($transfer);
    $transferRepo->expects($this->exactly(2))->method('save');

    /** @var AccountRepository&MockObject $accountRepo */
    $accountRepo = $this->createMock(AccountRepository::class);
    $accountRepo->method('findByIdForUpdate')->willReturnCallback(
        fn (AccountId $id): Account => (string) $id === $fromIdStr ? $fromAccount : $toAccount
    );

    /** @var LedgerEntryRepository $ledgerRepo */
    $ledgerRepo = $this->createStub(LedgerEntryRepository::class);
    $ledgerRepo->method('nextIdentity')->willReturn(LedgerEntryId::generate());

    /** @var AuditLogRepository&MockObject $auditRepo */
    $auditRepo = $this->createMock(AuditLogRepository::class);
    $auditRepo->method('nextIdentity')->willReturn(AuditLogId::generate());
    $auditRepo->expects($this->once())->method('append');

    $handler = new ProcessTransferHandler($conn, $transferRepo, $accountRepo, $ledgerRepo, $auditRepo, makeCachingFxService(), new NullLogger());
    $handler(new ProcessTransferCommand((string) $transfer->id));

    expect($transfer->status)->toBe(TransferStatus::Failed)
        ->and($transfer->failureReason)->toBe('account_closed');
});

test('fails transfer with insufficient_funds on DomainException', function (): void {
    $fromIdStr = (string) AccountId::generate();
    $toIdStr   = (string) AccountId::generate();

    // Transfer amount 100, but account only has 50
    $transfer = makePendingTransfer($fromIdStr, $toIdStr, '100.00');

    $fromAccount = Account::open(new AccountId($fromIdStr), UserId::generate(), new CurrencyCode('USD'), 'From');
    $fromAccount->credit(new Money('50.00', new CurrencyCode('USD')));

    $toAccount = Account::open(new AccountId($toIdStr), UserId::generate(), new CurrencyCode('USD'), 'To');

    /** @var Connection&MockObject $conn */
    $conn = $this->createMock(Connection::class);
    $conn->expects($this->once())->method('beginTransaction');
    $conn->expects($this->once())->method('rollBack');
    $conn->expects($this->never())->method('commit');

    /** @var TransferRepository&MockObject $transferRepo */
    $transferRepo = $this->createMock(TransferRepository::class);
    $transferRepo->method('findByIdForUpdate')->willReturn($transfer);
    // Called once for markProcessing, once for fail
    $transferRepo->expects($this->exactly(2))->method('save');

    /** @var AccountRepository&MockObject $accountRepo */
    $accountRepo = $this->createMock(AccountRepository::class);
    $accountRepo->method('findByIdForUpdate')->willReturnCallback(
        fn (AccountId $id): Account => (string) $id === $fromIdStr ? $fromAccount : $toAccount
    );

    /** @var LedgerEntryRepository $ledgerRepo */
    $ledgerRepo = $this->createStub(LedgerEntryRepository::class);
    $ledgerRepo->method('nextIdentity')->willReturn(LedgerEntryId::generate());

    /** @var AuditLogRepository&MockObject $auditRepo */
    $auditRepo = $this->createMock(AuditLogRepository::class);
    $auditRepo->method('nextIdentity')->willReturn(AuditLogId::generate());
    $auditRepo->expects($this->once())->method('append');

    $handler = new ProcessTransferHandler($conn, $transferRepo, $accountRepo, $ledgerRepo, $auditRepo, makeCachingFxService(), new NullLogger());
    $handler(new ProcessTransferCommand((string) $transfer->id));

    expect($transfer->status)->toBe(TransferStatus::Failed);
    expect($transfer->failureReason)->toBe('insufficient_funds');
});

test('failure audit log contains reason and error message', function (): void {
    $fromIdStr = (string) AccountId::generate();
    $toIdStr   = (string) AccountId::generate();
    $transfer  = makePendingTransfer($fromIdStr, $toIdStr, '100.00');

    $fromAccount = Account::open(new AccountId($fromIdStr), UserId::generate(), new CurrencyCode('USD'), 'From');
    $fromAccount->credit(new Money('50.00', new CurrencyCode('USD'))); // insufficient for 100

    $toAccount = Account::open(new AccountId($toIdStr), UserId::generate(), new CurrencyCode('USD'), 'To');

    /** @var Connection&MockObject $conn */
    $conn = $this->createMock(Connection::class);
    $conn->method('beginTransaction');
    $conn->method('rollBack');

    /** @var TransferRepository&MockObject $transferRepo */
    $transferRepo = $this->createMock(TransferRepository::class);
    $transferRepo->method('findByIdForUpdate')->willReturn($transfer);
    $transferRepo->method('save');

    /** @var AccountRepository&MockObject $accountRepo */
    $accountRepo = $this->createMock(AccountRepository::class);
    $accountRepo->method('findByIdForUpdate')->willReturnCallback(
        fn (AccountId $id): Account => (string) $id === $fromIdStr ? $fromAccount : $toAccount
    );

    /** @var LedgerEntryRepository $ledgerRepo */
    $ledgerRepo = $this->createStub(LedgerEntryRepository::class);
    $ledgerRepo->method('nextIdentity')->willReturn(LedgerEntryId::generate());

    /** @var AuditLogRepository&MockObject $auditRepo */
    $auditRepo = $this->createMock(AuditLogRepository::class);
    $auditRepo->method('nextIdentity')->willReturn(AuditLogId::generate());
    $auditRepo->expects($this->once())->method('append')
        ->with($this->callback(function (AuditLog $log): bool {
            $data = $log->newValue;
            return isset($data['reason'], $data['error'])
                && $data['reason'] === 'insufficient_funds';
        }));

    $handler = new ProcessTransferHandler($conn, $transferRepo, $accountRepo, $ledgerRepo, $auditRepo, makeCachingFxService(), new NullLogger());
    $handler(new ProcessTransferCommand((string) $transfer->id));
});
