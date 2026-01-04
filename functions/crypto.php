<?php
// ============================================================================
// PFAD: /functions/crypto.php
// ----------------------------------------------------------------------------
// Verschlüsselung (TOTP Secret) – AES-256-GCM
// ============================================================================

function authly_crypto_key(): string {
    // SETZE DAS in deiner config/ENV (WICHTIG!)
    // z.B. define('AUTHLY_CRYPTO_KEY', 'base64:....');
    if (defined('AUTHLY_CRYPTO_KEY')) {
        $k = (string)AUTHLY_CRYPTO_KEY;
        if (str_starts_with($k, 'base64:')) return base64_decode(substr($k, 7), true) ?: '';
        return $k;
    }
    // Fallback (nicht ideal): app key aus DB? -> hier bewusst hart abbrechen:
    return '';
}

function encrypt_gcm(string $plaintext): string {
    $key = authly_crypto_key();
    if ($key === '' || strlen($key) < 32) {
        throw new RuntimeException('AUTHLY_CRYPTO_KEY fehlt/zu kurz (min. 32 bytes).');
    }
    $key = substr(hash('sha256', $key, true), 0, 32);

    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) throw new RuntimeException('encrypt failed');

    return base64_encode($iv . $tag . $cipher);
}

function decrypt_gcm(string $enc): string {
    $key = authly_crypto_key();
    if ($key === '' || strlen($key) < 32) {
        throw new RuntimeException('AUTHLY_CRYPTO_KEY fehlt/zu kurz (min. 32 bytes).');
    }
    $key = substr(hash('sha256', $key, true), 0, 32);

    $raw = base64_decode($enc, true);
    if ($raw === false || strlen($raw) < 12 + 16) throw new RuntimeException('decrypt payload invalid');

    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);

    $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($plain === false) throw new RuntimeException('decrypt failed');
    return $plain;
}
