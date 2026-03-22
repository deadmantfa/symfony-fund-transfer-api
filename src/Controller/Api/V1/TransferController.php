<?php

declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Application\Transfer\DuplicateIdempotencyKeyException;
use App\Application\Transfer\InitiateTransferCommand;
use App\Application\Transfer\InitiateTransferHandler;
use App\Domain\Shared\ValueObject\Money;
use App\Domain\Transfer\Transfer;
use App\Domain\Transfer\TransferId;
use App\Domain\Transfer\TransferRepository;
use App\Security\SecurityUser;
use App\Security\Voter\TransferVoter;
use DateTimeInterface;
use DomainException;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Transfers')]
#[Route('/api/v1/transfers')]
final class TransferController extends AbstractController
{
    public function __construct(
        private readonly InitiateTransferHandler $initiateTransferHandler,
        private readonly TransferRepository $transferRepository,
    ) {
    }

    #[OA\Post(path: '/api/v1/transfers', description: 'Debits the source account and credits the destination. Cross-currency transfers apply the live FX rate. Requires a unique `Idempotency-Key` header (UUID v4) — retrying with the same key returns the original response.', summary: 'Initiate a fund transfer', requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['from_account_id', 'to_account_id', 'amount', 'currency'],
            properties: [
                new OA\Property(property: 'from_account_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'to_account_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'amount', type: 'string', example: '100.00'),
                new OA\Property(property: 'currency', type: 'string', example: 'USD'),
                new OA\Property(property: 'note', type: 'string', example: 'Rent payment', nullable: true),
            ],
        ),
    ), parameters: [
        new OA\Parameter(
            name: 'Idempotency-Key',
            description: 'UUID v4 — prevents duplicate processing on retries',
            in: 'header',
            required: true,
            schema: new OA\Schema(type: 'string', format: 'uuid'),
        ),
    ], responses: [
        new OA\Response(response: 202, description: 'Transfer accepted (async processing)'),
        new OA\Response(response: 200, description: 'Duplicate key — returns cached result'),
        new OA\Response(response: 409, description: 'Insufficient funds or self-transfer'),
        new OA\Response(response: 422, description: 'Missing Idempotency-Key header'),
        new OA\Response(response: 401, description: 'Unauthenticated'),
    ])]
    #[Route('', methods: ['POST'])]
    public function initiate(Request $request): JsonResponse
    {
        $idempotencyKey = $request->headers->get('Idempotency-Key');

        if (in_array($idempotencyKey, [null, '', '0'], true)) {
            return new JsonResponse(
                ['type' => 'validation_error', 'title' => 'Idempotency-Key header is required'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        /** @var array{from_account_id?: string, to_account_id?: string, amount?: string, currency?: string, note?: string} $data */
        $data = json_decode($request->getContent(), true) ?? [];

        $securityUser = $this->getUser();
        if (!$securityUser instanceof SecurityUser) {
            return new JsonResponse(['type' => 'unauthorized', 'title' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

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
        } catch (DomainException $e) {
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

    #[OA\Get(
        path: '/api/v1/transfers/{id}',
        summary: 'Get a transfer by ID',
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Transfer details'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Transfer not found'),
        ],
    )]
    #[Route('/{id}', methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        $transfer = $this->transferRepository->findById(new TransferId($id));

        if (!$transfer instanceof Transfer) {
            return new JsonResponse(['type' => 'not_found', 'title' => 'Transfer not found'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted(TransferVoter::VIEW, $transfer);

        return new JsonResponse(['data' => $this->serializeTransfer($transfer)]);
    }

    #[OA\Delete(
        path: '/api/v1/transfers/{id}',
        summary: 'Cancel a pending transfer',
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Transfer cancelled'),
            new OA\Response(response: 409, description: 'Cannot cancel a completed or already-failed transfer'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Transfer not found'),
        ],
    )]
    #[Route('/{id}', methods: ['DELETE'])]
    public function cancel(string $id): JsonResponse
    {
        $transfer = $this->transferRepository->findById(new TransferId($id));

        if (!$transfer instanceof Transfer) {
            return new JsonResponse(['type' => 'not_found', 'title' => 'Transfer not found'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted(TransferVoter::CANCEL, $transfer);

        try {
            $transfer->cancel();
            $this->transferRepository->save($transfer);
        } catch (DomainException $e) {
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
            'from_amount'     => $transfer->fromAmount instanceof Money ? (string) $transfer->fromAmount->amount : null,
            'from_currency'   => $transfer->fromAmount instanceof Money ? (string) $transfer->fromAmount->currency : null,
            'to_amount'       => $transfer->toAmount instanceof Money ? (string) $transfer->toAmount->amount : null,
            'to_currency'     => $transfer->toAmount instanceof Money ? (string) $transfer->toAmount->currency : null,
            'fx_rate'         => $transfer->fxRate,
            'status'          => $transfer->status->value,
            'failure_reason'  => $transfer->failureReason,
            'created_at'      => $transfer->createdAt->format(DateTimeInterface::ATOM),
            'updated_at'      => $transfer->updatedAt->format(DateTimeInterface::ATOM),
        ];
    }
}
