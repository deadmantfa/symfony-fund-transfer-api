<?php

declare(strict_types=1);

namespace App\Application\Transfer;

use App\Application\Shared\IdempotencyService;
use App\Domain\Account\AccountId;
use App\Domain\Account\AccountRepository;
use App\Domain\Shared\ValueObject\CurrencyCode;
use App\Domain\Shared\ValueObject\Money;
use App\Domain\Transfer\Transfer;
use App\Domain\Transfer\TransferRepository;
use App\Domain\User\UserId;
use App\Domain\User\UserRepository;
use Symfony\Component\Messenger\MessageBusInterface;

final class InitiateTransferHandler
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly AccountRepository $accountRepository,
        private readonly TransferRepository $transferRepository,
        private readonly IdempotencyService $idempotencyService,
        private readonly MessageBusInterface $messageBus,
    ) {}

    public function __invoke(InitiateTransferCommand $command): Transfer
    {
        // 1. Check idempotency key (Redis)
        if ($this->idempotencyService->exists($command->idempotencyKey)) {
            throw new DuplicateIdempotencyKeyException(
                $this->idempotencyService->get($command->idempotencyKey) ?? []
            );
        }

        // 2. Load and validate user
        $userId = new UserId($command->userId);
        $user   = $this->userRepository->findById($userId);

        if ($user === null) {
            throw new \DomainException('User not found.');
        }

        if (!$user->canInitiateTransfer()) {
            throw new \DomainException('User cannot initiate transfers. KYC verification required.');
        }

        // 3. Load and validate accounts
        $fromAccountId = new AccountId($command->fromAccountId);
        $toAccountId   = new AccountId($command->toAccountId);

        $fromAccount = $this->accountRepository->findById($fromAccountId);
        $toAccount   = $this->accountRepository->findById($toAccountId);

        if ($fromAccount === null || $toAccount === null) {
            throw new \DomainException('One or both accounts not found.');
        }

        if (!$fromAccount->isOwnedBy($userId)) {
            throw new \DomainException('You do not own the source account.');
        }

        if (!$fromAccount->status->allowsTransactions()) {
            throw new \DomainException("Source account is {$fromAccount->status->value}.");
        }

        if (!$toAccount->status->allowsTransactions()) {
            throw new \DomainException("Destination account is {$toAccount->status->value}.");
        }

        // 4. Server-side dedup
        if ($this->transferRepository->existsDuplicate(
            $fromAccountId, $toAccountId, $command->amount, $command->currency, 60
        )) {
            throw new \DomainException('Duplicate transfer detected. Please wait before retrying.');
        }

        // 5. Create pending transfer
        $transfer = Transfer::initiate(
            id: $this->transferRepository->nextIdentity(),
            fromAccountId: $fromAccountId,
            toAccountId: $toAccountId,
            amount: new Money($command->amount, new CurrencyCode($command->currency)),
            idempotencyKey: $command->idempotencyKey,
            initiatedByUserId: $userId,
            ipAddress: $command->ipAddress,
            userAgent: $command->userAgent,
        );

        $this->transferRepository->save($transfer);

        // 6. Cache idempotency key
        $this->idempotencyService->store($command->idempotencyKey, [
            'transfer_id' => (string) $transfer->id,
            'status'      => $transfer->status->value,
        ]);

        // 7. Dispatch to worker queue
        $this->messageBus->dispatch(new ProcessTransferCommand((string) $transfer->id));

        return $transfer;
    }
}
