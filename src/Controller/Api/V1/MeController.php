<?php

declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Security\SecurityUser;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[OA\Tag(name: 'Users')]
#[Route('/api/v1')]
final class MeController
{
    #[OA\Get(
        path: '/api/v1/me',
        summary: 'Quick profile endpoint — returns the authenticated user\'s basic details',
        responses: [
            new OA\Response(response: 200, description: 'User profile'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ],
    )]
    #[Route('/me', methods: ['GET'])]
    public function me(#[CurrentUser] SecurityUser $user): JsonResponse
    {
        return new JsonResponse([
            'data' => [
                'id'         => $user->user->id->value,
                'email'      => $user->user->email,
                'first_name' => $user->user->firstName,
                'last_name'  => $user->user->lastName,
            ],
        ]);
    }
}
