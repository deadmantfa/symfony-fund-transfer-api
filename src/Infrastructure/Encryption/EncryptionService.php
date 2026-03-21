<?php

declare(strict_types=1);

namespace App\Infrastructure\Encryption;

/**
 * Libsodium-based symmetric encryption for PII fields.
 * Uses XSalsa20-Poly1305 via sodium_crypto_secretbox.
 * Each encryption generates a random 24-byte nonce.
 */
final class EncryptionService
{
    private readonly string $key;

    public function __construct(string $keyHex)
    {
        if (strlen($keyHex) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES * 2) {
            throw new \InvalidArgumentException(
                'Encryption key must be a ' . (SODIUM_CRYPTO_SECRETBOX_KEYBYTES * 2) . '-char hex string.'
            );
        }

        $this->key = sodium_hex2bin($keyHex);
    }

    public function encrypt(string $plaintext): string
    {
        $nonce      = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $this->key);

        return sodium_bin2base64($nonce . $ciphertext, SODIUM_BASE64_VARIANT_URLSAFE);
    }

    public function decrypt(string $encoded): ?string
    {
        try {
            $decoded    = sodium_base642bin($encoded, SODIUM_BASE64_VARIANT_URLSAFE);
            $nonce      = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $ciphertext = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $plaintext  = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key);

            return $plaintext === false ? null : $plaintext;
        } catch (\Throwable) {
            return null;
        }
    }
}
