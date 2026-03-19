<?php

declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Security\SecurityUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1')]
final class MeController
{
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
