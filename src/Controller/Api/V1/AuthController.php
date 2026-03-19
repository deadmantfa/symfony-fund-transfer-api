<?php

declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Application\Auth\RegisterUserCommand;
use App\Application\Auth\RegisterUserHandler;
use App\Security\SecurityUser;
use Gesdinet\JWTRefreshTokenBundle\Generator\RefreshTokenGeneratorInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/auth')]
final class AuthController
{
    public function __construct(
        private readonly RegisterUserHandler $registerUserHandler,
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly RateLimiterFactory $registerLimiter,
        private readonly RefreshTokenGeneratorInterface $refreshTokenGenerator,
        private readonly RefreshTokenManagerInterface $refreshTokenManager,
        private readonly int $refreshTokenTtl,
    ) {
    }

    /**
     * Login is handled by Symfony's json_login firewall.
     * This route exists so the router can match the path.
     * The security component intercepts the request before it reaches here.
     */
    #[Route('/login', methods: ['POST'])]
    public function login(): never
    {
        throw new \LogicException('This method should not be reached — handled by the security firewall.');
    }

    #[Route('/register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        $limiter = $this->registerLimiter->create($request->getClientIp() ?? '127.0.0.1');
        if (!$limiter->consume()->isAccepted()) {
            return new JsonResponse(
                ['type' => 'https://tools.ietf.org/html/rfc6585#section-4', 'title' => 'Too many requests', 'status' => 429],
                Response::HTTP_TOO_MANY_REQUESTS,
            );
        }

        /** @var array{email?: string, password?: string, first_name?: string, last_name?: string} $data */
        $data = json_decode($request->getContent(), true) ?? [];

        try {
            $user = ($this->registerUserHandler)(new RegisterUserCommand(
                email: $data['email'] ?? '',
                password: $data['password'] ?? '',
                firstName: $data['first_name'] ?? '',
                lastName: $data['last_name'] ?? '',
            ));
        } catch (\DomainException $e) {
            return new JsonResponse(
                ['type' => 'https://tools.ietf.org/html/rfc7807', 'title' => $e->getMessage(), 'status' => 409],
                Response::HTTP_CONFLICT,
            );
        }

        $securityUser = new SecurityUser($user);
        $token = $this->jwtManager->create($securityUser);

        $refreshToken = $this->refreshTokenGenerator->createForUserWithTtl($securityUser, $this->refreshTokenTtl);
        $this->refreshTokenManager->save($refreshToken);

        return new JsonResponse(
            ['data' => ['token' => $token, 'refresh_token' => $refreshToken->getRefreshToken()]],
            Response::HTTP_CREATED,
        );
    }
}
