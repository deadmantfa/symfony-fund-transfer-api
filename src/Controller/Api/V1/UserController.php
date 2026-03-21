<?php

declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Domain\Account\AccountRepository;
use App\Domain\Transfer\TransferRepository;
use App\Domain\User\UserRepository;
use App\Security\SecurityUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/users')]
final class UserController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly AccountRepository $accountRepository,
        private readonly TransferRepository $transferRepository,
    ) {
    }

    #[Route('/me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        $securityUser = $this->getUser();
        if (!$securityUser instanceof SecurityUser) {
            return new JsonResponse(['type' => 'unauthorized', 'title' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }
        $user = $securityUser->user;

        return new JsonResponse(['data' => [
            'id'         => (string) $user->id,
            'email'      => $user->email,
            'first_name' => $user->firstName,
            'last_name'  => $user->lastName,
            'kyc_status' => $user->kycStatus->value,
            'status'     => $user->status->value,
            'created_at' => $user->createdAt->format(\DateTimeInterface::ATOM),
        ]]);
    }

    #[Route('/me/export', methods: ['GET'])]
    public function export(): JsonResponse
    {
        $securityUser = $this->getUser();
        if (!$securityUser instanceof SecurityUser) {
            return new JsonResponse(['type' => 'unauthorized', 'title' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }
        $user      = $securityUser->user;
        $accounts  = $this->accountRepository->findByUserId($user->id);
        $transfers = [];
        foreach ($accounts as $account) {
            foreach ($this->transferRepository->findByAccountId($account->id, null, 100) as $t) {
                $transfers[(string) $t->id] = [
                    'id'             => (string) $t->id,
                    'from_account'   => (string) $t->fromAccountId,
                    'to_account'     => (string) $t->toAccountId,
                    'amount'         => (string) $t->amount->amount,
                    'currency'       => (string) $t->amount->currency,
                    'status'         => $t->status->value,
                    'created_at'     => $t->createdAt->format(\DateTimeInterface::ATOM),
                ];
            }
        }

        return new JsonResponse(['data' => [
            'user'      => [
                'id'         => (string) $user->id,
                'email'      => $user->email,
                'first_name' => $user->firstName,
                'last_name'  => $user->lastName,
                'kyc_status' => $user->kycStatus->value,
                'created_at' => $user->createdAt->format(\DateTimeInterface::ATOM),
            ],
            'accounts'  => array_map(fn ($a) => [
                'id'       => (string) $a->id,
                'currency' => (string) $a->currency,
                'name'     => $a->name,
                'balance'  => (string) $a->balance->amount,
            ], $accounts),
            'transfers' => array_values($transfers),
        ]]);
    }

    #[Route('/me', methods: ['DELETE'])]
    public function delete(): JsonResponse
    {
        $securityUser = $this->getUser();
        if (!$securityUser instanceof SecurityUser) {
            return new JsonResponse(['type' => 'unauthorized', 'title' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }
        $user = $securityUser->user;

        $user->close();
        $this->userRepository->save($user);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
