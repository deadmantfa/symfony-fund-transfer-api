<?php

declare(strict_types=1);

use App\Infrastructure\Encryption\EncryptionService;

test('encrypts and decrypts string correctly', function (): void {
    $key     = sodium_bin2hex(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $service = new EncryptionService($key);

    $plaintext  = 'sensitive-data-123';
    $ciphertext = $service->encrypt($plaintext);

    expect($ciphertext)->not->toBe($plaintext);
    expect($service->decrypt($ciphertext))->toBe($plaintext);
});

test('different encryptions of same value produce different ciphertext', function (): void {
    $key     = sodium_bin2hex(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $service = new EncryptionService($key);

    $a = $service->encrypt('same-value');
    $b = $service->encrypt('same-value');

    expect($a)->not->toBe($b);
    expect($service->decrypt($a))->toBe('same-value');
    expect($service->decrypt($b))->toBe('same-value');
});

test('decrypt returns null for invalid ciphertext', function (): void {
    $key     = sodium_bin2hex(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $service = new EncryptionService($key);

    expect($service->decrypt('invalid-base64-garbage'))->toBeNull();
});
