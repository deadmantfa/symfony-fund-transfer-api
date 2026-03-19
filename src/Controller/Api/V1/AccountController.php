<?php

declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Application\Account\CloseAccountCommand;
use App\Application\Account\CloseAccountHandler;
use App\Application\Account\CreateAccountCommand;
use App\Application\Account\CreateAccountHandler;
use App\Application\Account\UpdateAccountCommand;
use App\Application\Account\UpdateAccountHandler;
use App\Domain\Account\Account;
use App\Domain\Account\AccountId;
use App\Domain\Account\AccountRepository;
use App\Security\SecurityUser;
use App\Security\Voter\AccountVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/accounts')]
final class AccountController extends AbstractController
{
    public function __construct(
        private readonly CreateAccountHandler $createHandler,
        private readonly CloseAccountHandler $closeHandler,
        private readonly UpdateAccountHandler $updateHandler,
        private readonly AccountRepository $accountRepository,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function list(#[CurrentUser] SecurityUser $user): JsonResponse
    {
        $accounts = $this->accountRepository->findByUserId($user->user->id);

        return new JsonResponse([
            'data' => array_map($this->serialize(...), $accounts),
        ]);
    }

    #[Route('', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] SecurityUser $user): JsonResponse
    {
        /** @var array{currency?: string, name?: string} $data */
        $data = json_decode($request->getContent(), true) ?? [];

        try {
            $account = ($this->createHandler)(new CreateAccountCommand(
                userId: (string) $user->user->id,
                currency: $data['currency'] ?? '',
                name: $data['name'] ?? '',
            ));
        } catch (\DomainException $e) {
            return new JsonResponse(
                ['type' => 'https://tools.ietf.org/html/rfc7807', 'title' => $e->getMessage(), 'status' => 409],
                Response::HTTP_CONFLICT,
            );
        }

        return new JsonResponse(['data' => $this->serialize($account)], Response::HTTP_CREATED);
    }

    #[Route('/{id}', methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        $account = $this->accountRepository->findById(new AccountId($id));
        if ($account === null) {
            return new JsonResponse(['type' => 'not_found', 'title' => 'Account not found'], Response::HTTP_NOT_FOUND);
        }
        $this->denyAccessUnlessGranted(AccountVoter::VIEW, $account);

        return new JsonResponse(['data' => $this->serialize($account)]);
    }

    #[Route('/{id}', methods: ['PATCH'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $account = $this->accountRepository->findById(new AccountId($id));
        if ($account === null) {
            return new JsonResponse(['type' => 'not_found', 'title' => 'Account not found'], Response::HTTP_NOT_FOUND);
        }
        $this->denyAccessUnlessGranted(AccountVoter::EDIT, $account);

        /** @var array{name?: string} $data */
        $data = json_decode($request->getContent(), true) ?? [];

        try {
            ($this->updateHandler)(new UpdateAccountCommand(
                accountId: $id,
                name: $data['name'] ?? null,
            ));
        } catch (\DomainException $e) {
            return new JsonResponse(
                ['type' => 'https://tools.ietf.org/html/rfc7807', 'title' => $e->getMessage(), 'status' => 409],
                Response::HTTP_CONFLICT,
            );
        }

        return new JsonResponse(['data' => $this->serialize(
            $this->accountRepository->findById(new AccountId($id)) ?? $account,
        )]);
    }

    #[Route('/{id}', methods: ['DELETE'])]
    public function close(string $id): JsonResponse
    {
        $account = $this->accountRepository->findById(new AccountId($id));
        if ($account === null) {
            return new JsonResponse(['type' => 'not_found', 'title' => 'Account not found'], Response::HTTP_NOT_FOUND);
        }
        $this->denyAccessUnlessGranted(AccountVoter::DELETE, $account);

        try {
            ($this->closeHandler)(new CloseAccountCommand($id));
        } catch (\DomainException $e) {
            return new JsonResponse(
                ['type' => 'https://tools.ietf.org/html/rfc7807', 'title' => $e->getMessage(), 'status' => 409],
                Response::HTTP_CONFLICT,
            );
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /** @return array<string, mixed> */
    private function serialize(Account $account): array
    {
        return [
            'id'         => (string) $account->id,
            'currency'   => (string) $account->currency,
            'name'       => $account->name,
            'balance'    => (string) $account->balance->amount,
            'status'     => $account->status->value,
            'created_at' => $account->createdAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
