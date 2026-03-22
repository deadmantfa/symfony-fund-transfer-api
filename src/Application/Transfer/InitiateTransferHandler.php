<?php

declare(strict_types=1);

namespace App\Application\Transfer;

use App\Application\Shared\IdempotencyService;
use App\Domain\Account\Account;
use App\Domain\Account\AccountId;
use App\Domain\Account\AccountRepository;
use App\Domain\Shared\ValueObject\CurrencyCode;
use App\Domain\Shared\ValueObject\Money;
use App\Domain\Transfer\Transfer;
use App\Domain\Transfer\TransferRepository;
use App\Domain\User\User;
use App\Domain\User\UserId;
use App\Domain\User\UserRepository;
use DomainException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class InitiateTransferHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private AccountRepository $accountRepository,
        private TransferRepository $transferRepository,
        private IdempotencyService $idempotencyService,
        private MessageBusInterface $messageBus,
        #[Autowire(service: 'monolog.logger.transfer')]
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(InitiateTransferCommand $command): Transfer
    {
        // 2. Load and validate user
        $userId = new UserId($command->userId);

        // 1. Check idempotency key (Redis) — scoped to user to prevent cross-user key collision
        $scopedKey = $command->userId . '.' . $command->idempotencyKey;
        if ($this->idempotencyService->exists($scopedKey)) {
            throw new DuplicateIdempotencyKeyException(
                $this->idempotencyService->get($scopedKey) ?? []
            );
        }

        $user   = $this->userRepository->findById($userId);

        if (!$user instanceof User) {
            throw new DomainException('User not found.');
        }

        if (!$user->canInitiateTransfer()) {
            throw new DomainException('User cannot initiate transfers. KYC verification required.');
        }

        // 3. Load and validate accounts
        $fromAccountId = new AccountId($command->fromAccountId);
        $toAccountId   = new AccountId($command->toAccountId);

        $fromAccount = $this->accountRepository->findById($fromAccountId);
        $toAccount   = $this->accountRepository->findById($toAccountId);

        if (!$fromAccount instanceof Account || !$toAccount instanceof Account) {
            throw new DomainException('One or both accounts not found.');
        }

        if ($fromAccountId->equals($toAccountId)) {
            throw new DomainException('Cannot transfer to the same account.');
        }

        if (!$fromAccount->isOwnedBy($userId)) {
            throw new DomainException('You do not own the source account.');
        }

        if (!$fromAccount->status->allowsTransactions()) {
            throw new DomainException("Source account is {$fromAccount->status->value}.");
        }

        if (!$toAccount->status->allowsTransactions()) {
            throw new DomainException("Destination account is {$toAccount->status->value}.");
        }

        // 4. Server-side dedup
        if ($this->transferRepository->existsDuplicate(
            $fromAccountId,
            $toAccountId,
            $command->amount,
            $command->currency,
            60
        )) {
            throw new DomainException('Duplicate transfer detected. Please wait before retrying.');
        }

        // 5. Create pending transfer
        $amount = new Money($command->amount, new CurrencyCode($command->currency));
        if (!$amount->isPositive()) {
            throw new DomainException('Transfer amount must be positive.');
        }

        $transfer = Transfer::initiate(
            id: $this->transferRepository->nextIdentity(),
            fromAccountId: $fromAccountId,
            toAccountId: $toAccountId,
            amount: $amount,
            idempotencyKey: $command->idempotencyKey,
            initiatedByUserId: $userId,
            ipAddress: $command->ipAddress,
            userAgent: $command->userAgent,
        );

        $this->transferRepository->save($transfer);

        // 6. Cache idempotency key — store same shape as the 202 response so that
        // a 200 replay returns data.id, consistent with the new-transfer response.
        $this->idempotencyService->store($scopedKey, [
            'id'     => (string) $transfer->id,
            'status' => $transfer->status->value,
        ]);

        // 7. Dispatch to worker queue
        $this->messageBus->dispatch(new ProcessTransferCommand((string) $transfer->id));

        $this->logger->info('transfer.initiated', [
            'transfer_id'     => (string) $transfer->id,
            'user_id'         => $command->userId,
            'from_account_id' => $command->fromAccountId,
            'to_account_id'   => $command->toAccountId,
            'amount'          => $command->amount,
            'currency'        => $command->currency,
        ]);

        return $transfer;
    }
}
