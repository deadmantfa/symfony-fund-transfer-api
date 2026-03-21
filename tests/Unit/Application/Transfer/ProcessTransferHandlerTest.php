<?php

declare(strict_types=1);

use App\Application\Transfer\ProcessTransferCommand;
use App\Application\Transfer\ProcessTransferHandler;
use App\Domain\Account\Account;
use App\Domain\Account\AccountId;
use App\Domain\Account\AccountRepository;
use App\Domain\AuditLog\AuditLogId;
use App\Domain\AuditLog\AuditLogRepository;
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
use Doctrine\DBAL\Connection;
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
        public function findLatest(\App\Domain\Shared\ValueObject\CurrencyCode $from, \App\Domain\Shared\ValueObject\CurrencyCode $to): ?\App\Domain\FxRate\FxRate
        {
            return null;
        }
        public function save(\App\Domain\FxRate\FxRate $rate): void
        {
        }
        public function nextIdentity(): \App\Domain\FxRate\FxRateId
        {
            return \App\Domain\FxRate\FxRateId::generate();
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

    /** @var Connection&\PHPUnit\Framework\MockObject\MockObject $conn */
    $conn = $this->createMock(Connection::class);
    $conn->expects($this->once())->method('beginTransaction');
    $conn->expects($this->once())->method('commit');
    $conn->expects($this->never())->method('rollBack');

    /** @var TransferRepository&\PHPUnit\Framework\MockObject\MockObject $transferRepo */
    $transferRepo = $this->createMock(TransferRepository::class);
    $transferRepo->method('findByIdForUpdate')->willReturn($transfer);
    $transferRepo->expects($this->exactly(2))->method('save');

    /** @var AccountRepository&\PHPUnit\Framework\MockObject\MockObject $accountRepo */
    $accountRepo = $this->createMock(AccountRepository::class);
    $accountRepo->method('findByIdForUpdate')->willReturnCallback(
        fn (AccountId $id) => (string) $id === $fromIdStr ? $fromAccount : $toAccount
    );
    $accountRepo->expects($this->exactly(2))->method('save');

    /** @var LedgerEntryRepository&\PHPUnit\Framework\MockObject\MockObject $ledgerRepo */
    $ledgerRepo = $this->createMock(LedgerEntryRepository::class);
    $ledgerRepo->method('nextIdentity')->willReturn(LedgerEntryId::generate());
    $ledgerRepo->expects($this->exactly(2))->method('append');

    /** @var AuditLogRepository&\PHPUnit\Framework\MockObject\MockObject $auditRepo */
    $auditRepo = $this->createMock(AuditLogRepository::class);
    $auditRepo->method('nextIdentity')->willReturn(AuditLogId::generate());
    $auditRepo->expects($this->once())->method('append');

    $handler = new ProcessTransferHandler($conn, $transferRepo, $accountRepo, $ledgerRepo, $auditRepo, makeCachingFxService());
    $handler(new ProcessTransferCommand((string) $transfer->id));

    expect($transfer->status)->toBe(TransferStatus::Completed);
    // Same currency (USD->USD), rate is 1, debit 50, credit 50
    expect((string) $fromAccount->balance->amount)->toBe('150.00000000');
    // multiply with rate 1.0000000000 gives 10 decimal places via BigDecimal
    expect($toAccount->balance->amount->toFloat())->toBe(50.0);
});

test('processes cross-currency transfer: credit destination in target currency', function (): void {
    $fromIdStr = (string) AccountId::generate();
    $toIdStr   = (string) AccountId::generate();

    $transfer = makePendingTransfer($fromIdStr, $toIdStr, '100.00'); // USD amount

    $fromAccount = Account::open(new AccountId($fromIdStr), UserId::generate(), new CurrencyCode('USD'), 'From USD');
    $fromAccount->credit(new Money('200.00', new CurrencyCode('USD')));

    $toAccount = Account::open(new AccountId($toIdStr), UserId::generate(), new CurrencyCode('EUR'), 'To EUR');

    /** @var Connection&\PHPUnit\Framework\MockObject\MockObject $conn */
    $conn = $this->createMock(Connection::class);
    $conn->expects($this->once())->method('beginTransaction');
    $conn->expects($this->once())->method('commit');

    /** @var TransferRepository&\PHPUnit\Framework\MockObject\MockObject $transferRepo */
    $transferRepo = $this->createMock(TransferRepository::class);
    $transferRepo->method('findByIdForUpdate')->willReturn($transfer);
    $transferRepo->expects($this->exactly(2))->method('save');

    /** @var AccountRepository&\PHPUnit\Framework\MockObject\MockObject $accountRepo */
    $accountRepo = $this->createMock(AccountRepository::class);
    $accountRepo->method('findByIdForUpdate')->willReturnCallback(
        fn (AccountId $id) => (string) $id === $fromIdStr ? $fromAccount : $toAccount
    );
    $accountRepo->expects($this->exactly(2))->method('save');

    /** @var LedgerEntryRepository&\PHPUnit\Framework\MockObject\MockObject $ledgerRepo */
    $ledgerRepo = $this->createMock(LedgerEntryRepository::class);
    $ledgerRepo->method('nextIdentity')->willReturn(LedgerEntryId::generate());
    $ledgerRepo->expects($this->exactly(2))->method('append');

    /** @var AuditLogRepository&\PHPUnit\Framework\MockObject\MockObject $auditRepo */
    $auditRepo = $this->createMock(AuditLogRepository::class);
    $auditRepo->method('nextIdentity')->willReturn(AuditLogId::generate());
    $auditRepo->expects($this->once())->method('append');

    $handler = new ProcessTransferHandler($conn, $transferRepo, $accountRepo, $ledgerRepo, $auditRepo, makeCachingFxService());
    $handler(new ProcessTransferCommand((string) $transfer->id));

    expect($transfer->status)->toBe(TransferStatus::Completed);
    // Destination balance must be in EUR (not USD)
    expect($toAccount->balance->currency->value)->toBe('EUR');
    expect($toAccount->balance->amount->isGreaterThan(\Brick\Math\BigDecimal::zero()))->toBeTrue();
});

test('fails transfer with insufficient_funds on DomainException', function (): void {
    $fromIdStr = (string) AccountId::generate();
    $toIdStr   = (string) AccountId::generate();

    // Transfer amount 100, but account only has 50
    $transfer = makePendingTransfer($fromIdStr, $toIdStr, '100.00');

    $fromAccount = Account::open(new AccountId($fromIdStr), UserId::generate(), new CurrencyCode('USD'), 'From');
    $fromAccount->credit(new Money('50.00', new CurrencyCode('USD')));

    $toAccount = Account::open(new AccountId($toIdStr), UserId::generate(), new CurrencyCode('USD'), 'To');

    /** @var Connection&\PHPUnit\Framework\MockObject\MockObject $conn */
    $conn = $this->createMock(Connection::class);
    $conn->expects($this->once())->method('beginTransaction');
    $conn->expects($this->once())->method('rollBack');
    $conn->expects($this->never())->method('commit');

    /** @var TransferRepository&\PHPUnit\Framework\MockObject\MockObject $transferRepo */
    $transferRepo = $this->createMock(TransferRepository::class);
    $transferRepo->method('findByIdForUpdate')->willReturn($transfer);
    // Called once for markProcessing, once for fail
    $transferRepo->expects($this->exactly(2))->method('save');

    /** @var AccountRepository&\PHPUnit\Framework\MockObject\MockObject $accountRepo */
    $accountRepo = $this->createMock(AccountRepository::class);
    $accountRepo->method('findByIdForUpdate')->willReturnCallback(
        fn (AccountId $id) => (string) $id === $fromIdStr ? $fromAccount : $toAccount
    );

    /** @var LedgerEntryRepository $ledgerRepo */
    $ledgerRepo = $this->createStub(LedgerEntryRepository::class);
    $ledgerRepo->method('nextIdentity')->willReturn(LedgerEntryId::generate());

    /** @var AuditLogRepository&\PHPUnit\Framework\MockObject\MockObject $auditRepo */
    $auditRepo = $this->createMock(AuditLogRepository::class);
    $auditRepo->method('nextIdentity')->willReturn(AuditLogId::generate());
    $auditRepo->expects($this->once())->method('append');

    $handler = new ProcessTransferHandler($conn, $transferRepo, $accountRepo, $ledgerRepo, $auditRepo, makeCachingFxService());
    $handler(new ProcessTransferCommand((string) $transfer->id));

    expect($transfer->status)->toBe(TransferStatus::Failed);
    expect($transfer->failureReason)->toBe('insufficient_funds');
});
