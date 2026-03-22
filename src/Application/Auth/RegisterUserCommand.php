<?php

declare(strict_types=1);

namespace App\Application\Auth;

final readonly class RegisterUserCommand
{
    public function __construct(
        public string $email,
        public string $password,
        public string $firstName,
        public string $lastName,
        public ?string $phoneNumber = null,
    ) {
    }
}
