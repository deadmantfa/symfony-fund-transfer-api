<?php

declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Application\Transfer\DuplicateIdempotencyKeyException;
use App\Application\Transfer\InitiateTransferCommand;
use App\Application\Transfer\InitiateTransferHandler;
use App\Domain\Transfer\Transfer;
use App\Domain\Transfer\TransferId;
use App\Domain\Transfer\TransferRepository;
use App\Security\SecurityUser;
use App\Security\Voter\TransferVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/transfers')]
final class TransferController extends AbstractController
{
    public function __construct(
        private readonly InitiateTransferHandler $initiateTransferHandler,
        private readonly TransferRepository $transferRepository,
    ) {}

    #[Route('', methods: ['POST'])]
    public function initiate(Request $request): JsonResponse
    {
        $idempotencyKey = $request->headers->get('Idempotency-Key');

        if (empty($idempotencyKey)) {
            return new JsonResponse(
                ['type' => 'validation_error', 'title' => 'Idempotency-Key header is required'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        /** @var array{from_account_id?: string, to_account_id?: string, amount?: string, currency?: string, note?: string} $data */
        $data = json_decode($request->getContent(), true) ?? [];

        /** @var SecurityUser $securityUser */
        $securityUser = $this->getUser();

        try {
            $transfer = ($this->initiateTransferHandler)(new InitiateTransferCommand(
                userId: (string) $securityUser->user->id,
                fromAccountId: $data['from_account_id'] ?? '',
                toAccountId: $data['to_account_id'] ?? '',
                amount: $data['amount'] ?? '',
                currency: $data['currency'] ?? '',
                idempotencyKey: $idempotencyKey,
                ipAddress: $request->getClientIp() ?? '',
                userAgent: $request->headers->get('User-Agent', ''),
                note: $data['note'] ?? null,
            ));
        } catch (DuplicateIdempotencyKeyException $e) {
            return new JsonResponse(['data' => $e->cachedResponse], Response::HTTP_OK);
        } catch (\DomainException $e) {
            return new JsonResponse(
                ['type' => 'conflict', 'title' => $e->getMessage()],
                Response::HTTP_CONFLICT
            );
        }

        return new JsonResponse(
            ['data' => $this->serializeTransfer($transfer)],
            Response::HTTP_ACCEPTED,
            ['Location' => "/api/v1/transfers/{$transfer->id}"]
        );
    }

    #[Route('/{id}', methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        $transfer = $this->transferRepository->findById(new TransferId($id));

        if ($transfer === null) {
            return new JsonResponse(['type' => 'not_found', 'title' => 'Transfer not found'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted(TransferVoter::VIEW, $transfer);

        return new JsonResponse(['data' => $this->serializeTransfer($transfer)]);
    }

    #[Route('/{id}', methods: ['DELETE'])]
    public function cancel(string $id): JsonResponse
    {
        $transfer = $this->transferRepository->findById(new TransferId($id));

        if ($transfer === null) {
            return new JsonResponse(['type' => 'not_found', 'title' => 'Transfer not found'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted(TransferVoter::CANCEL, $transfer);

        try {
            $transfer->cancel();
            $this->transferRepository->save($transfer);
        } catch (\DomainException $e) {
            return new JsonResponse(
                ['type' => 'conflict', 'title' => $e->getMessage()],
                Response::HTTP_CONFLICT
            );
        }

        return new JsonResponse(['data' => $this->serializeTransfer($transfer)]);
    }

    /** @return array<string, mixed> */
    private function serializeTransfer(Transfer $transfer): array
    {
        return [
            'id'              => (string) $transfer->id,
            'from_account_id' => (string) $transfer->fromAccountId,
            'to_account_id'   => (string) $transfer->toAccountId,
            'amount'          => (string) $transfer->amount->amount,
            'currency'        => (string) $transfer->amount->currency,
            'from_amount'     => $transfer->fromAmount !== null ? (string) $transfer->fromAmount->amount : null,
            'from_currency'   => $transfer->fromAmount !== null ? (string) $transfer->fromAmount->currency : null,
            'to_amount'       => $transfer->toAmount !== null ? (string) $transfer->toAmount->amount : null,
            'to_currency'     => $transfer->toAmount !== null ? (string) $transfer->toAmount->currency : null,
            'fx_rate'         => $transfer->fxRate,
            'status'          => $transfer->status->value,
            'failure_reason'  => $transfer->failureReason,
            'created_at'      => $transfer->createdAt->format(\DateTimeInterface::ATOM),
            'updated_at'      => $transfer->updatedAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
