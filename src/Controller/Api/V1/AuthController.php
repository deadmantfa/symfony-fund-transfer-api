<?php

declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Application\Auth\RegisterUserCommand;
use App\Application\Auth\RegisterUserHandler;
use App\Security\SecurityUser;
use DomainException;
use Gesdinet\JWTRefreshTokenBundle\Generator\RefreshTokenGeneratorInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use LogicException;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Auth')]
#[Route('/api/v1/auth')]
final readonly class AuthController
{
    public function __construct(
        private RegisterUserHandler $registerUserHandler,
        private JWTTokenManagerInterface $jwtManager,
        private RateLimiterFactory $registerLimiter,
        private RefreshTokenGeneratorInterface $refreshTokenGenerator,
        private RefreshTokenManagerInterface $refreshTokenManager,
        private int $refreshTokenTtl,
    ) {
    }

    /**
     * Login is handled by Symfony's json_login firewall.
     * This route exists so the router can match the path.
     * The security component intercepts the request before it reaches here.
     */
    #[OA\Post(
        path: '/api/v1/auth/login',
        summary: 'Obtain a JWT access token',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['username', 'password'],
                properties: [
                    new OA\Property(property: 'username', type: 'string', format: 'email', example: 'user@example.com'),
                    new OA\Property(property: 'password', type: 'string', format: 'password', example: 'Password123!'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'JWT token pair',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'token', type: 'string'),
                    new OA\Property(property: 'refresh_token', type: 'string'),
                ]),
            ),
            new OA\Response(response: 401, description: 'Invalid credentials'),
        ],
        security: [],
    )]
    #[Route('/login', methods: ['POST'])]
    public function login(): never
    {
        throw new LogicException('This method should not be reached — handled by the security firewall.');
    }

    #[OA\Post(
        path: '/api/v1/auth/register',
        summary: 'Register a new user and receive a JWT token pair',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email', 'password', 'first_name', 'last_name'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'alice@example.com'),
                    new OA\Property(property: 'password', type: 'string', format: 'password', example: 'Password123!'),
                    new OA\Property(property: 'first_name', type: 'string', example: 'Alice'),
                    new OA\Property(property: 'last_name', type: 'string', example: 'Smith'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Registration successful — returns JWT token pair',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'token', type: 'string'),
                        new OA\Property(property: 'refresh_token', type: 'string'),
                    ], type: 'object'),
                ]),
            ),
            new OA\Response(response: 409, description: 'Email already registered'),
            new OA\Response(response: 422, description: 'Validation error — missing required fields'),
            new OA\Response(response: 429, description: 'Rate limit exceeded'),
        ],
        security: [],
    )]
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

        /** @var array{email?: string, password?: string, first_name?: string, last_name?: string}|null $decoded */
        $decoded = json_decode($request->getContent(), true);

        if ($decoded === null && $request->getContent() !== '') {
            return new JsonResponse(
                ['type' => 'https://tools.ietf.org/html/rfc9110#section-15.5.21', 'title' => 'Unprocessable Content', 'status' => 422, 'detail' => 'Request body must be valid JSON.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        /** @var array{email?: string, password?: string, first_name?: string, last_name?: string} $data */
        $data = $decoded ?? [];

        $missing = [];
        foreach (['email', 'password', 'first_name', 'last_name'] as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                $missing[] = $field;
            }
        }

        if ($missing !== []) {
            return new JsonResponse(
                ['type' => 'https://tools.ietf.org/html/rfc9110#section-15.5.21', 'title' => 'Unprocessable Content', 'status' => 422, 'detail' => 'Missing required fields: ' . implode(', ', $missing)],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        try {
            $user = ($this->registerUserHandler)(new RegisterUserCommand(
                email: $data['email'] ?? '',
                password: $data['password'] ?? '',
                firstName: $data['first_name'] ?? '',
                lastName: $data['last_name'] ?? '',
            ));
        } catch (DomainException $e) {
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
