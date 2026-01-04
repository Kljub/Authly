<?php
// ============================================================================
// PFAD: /functions/mfa_totp.php
// ----------------------------------------------------------------------------
// RFC6238 TOTP (Authenticator App) + Base32 + Backup-Codes Hashing
// ============================================================================

function base32_decode_authly(string $b32): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(preg_replace('/[^A-Z2-7]/', '', $b32));
    $bits = '';
    for ($i = 0; $i < strlen($b32); $i++) {
        $v = strpos($alphabet, $b32[$i]);
        if ($v === false) continue;
        $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    for ($i = 0; $i + 8 <= strlen($bits); $i += 8) {
        $out .= chr(bindec(substr($bits, $i, 8)));
    }
    return $out;
}

function base32_encode_authly(string $raw): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    for ($i = 0; $i < strlen($raw); $i++) {
        $bits .= str_pad(decbin(ord($raw[$i])), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    for ($i = 0; $i < strlen($bits); $i += 5) {
        $chunk = substr($bits, $i, 5);
        if (strlen($chunk) < 5) $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
        $out .= $alphabet[bindec($chunk)];
    }
    // padding weglassen
    return $out;
}

function totp_generate_secret(int $bytes = 20): string {
    return base32_encode_authly(random_bytes($bytes));
}

function totp_now(string $secretB32, int $digits = 6, int $period = 30, ?int $time = null): string {
    $time = $time ?? time();
    $counter = intdiv($time, $period);

    $key = base32_decode_authly($secretB32);
    $binCounter = pack('N*', 0) . pack('N*', $counter); // 8 bytes
    $hash = hash_hmac('sha1', $binCounter, $key, true);

    $offset = ord(substr($hash, -1)) & 0x0F;
    $part = substr($hash, $offset, 4);
    $value = unpack('N', $part)[1] & 0x7FFFFFFF;

    $mod = 10 ** $digits;
    return str_pad((string)($value % $mod), $digits, '0', STR_PAD_LEFT);
}

function totp_verify(string $secretB32, string $code, int $window = 1, int $digits = 6, int $period = 30): bool {
    $code = preg_replace('/\s+/', '', $code);
    if (!preg_match('/^\d{6,8}$/', $code)) return false;

    $now = time();
    for ($i = -$window; $i <= $window; $i++) {
        $t = $now + ($i * $period);
        if (hash_equals(totp_now($secretB32, $digits, $period, $t), $code)) return true;
    }
    return false;
}

function backup_codes_generate(int $count = 10): array {
    $codes = [];
    for ($i = 0; $i < $count; $i++) {
        // 10-stellig in Gruppen
        $raw = bin2hex(random_bytes(5)); // 10 hex chars
        $codes[] = strtoupper(substr($raw, 0, 5) . '-' . substr($raw, 5, 5));
    }
    return $codes;
}

function backup_code_hash(string $code, string $pepper): string {
    $norm = strtoupper(trim($code));
    return hash('sha256', $pepper . '|' . $norm);
}
