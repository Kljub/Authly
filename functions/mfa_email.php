<?php
// ============================================================================
// PFAD: /functions/mfa_email.php
// ----------------------------------------------------------------------------
// Email MFA (OTP) – Code erzeugen, hashen, DB speichern, Mail senden, verify
// ============================================================================

function authly_send_mail(string $to, string $subject, string $html, string $text = ''): bool {
    // 1) Falls du bereits eine Mail-Funktion hast, nutzen wir sie.
    // Beispiele: sendMail(), send_mail(), authly_mail_send(), smtp_send() ...
    if (function_exists('sendMail')) {
        return (bool)sendMail($to, $subject, $html);
    }
    if (function_exists('send_mail')) {
        return (bool)send_mail($to, $subject, $html, $text);
    }
    if (function_exists('authly_mail_send')) {
        return (bool)authly_mail_send($to, $subject, $html, $text);
    }

    // 2) Fallback: PHP mail() (nur wenn SMTP nicht verkabelt ist)
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type:text/html; charset=UTF-8\r\n";
    return @mail($to, $subject, $html, $headers);
}

function email_mfa_generate_code(int $digits = 6): string {
    $min = 10 ** ($digits - 1);
    $max = (10 ** $digits) - 1;
    return (string)random_int($min, $max);
}

function email_mfa_hash(string $code, string $pepper): string {
    $norm = preg_replace('/\s+/', '', (string)$code);
    return hash('sha256', $pepper . '|mfa_email|' . $norm);
}

/**
 * Erstellt einen neuen Email-MFA Code in DB (hashed) und sendet Mail.
 */
function email_mfa_issue(PDO $pdo, int $userId, string $email, string $username, string $pepper, int $ttlSeconds = 600): void {
    // Alte ungenutzte Codes invalidieren (optional)
    $pdo->prepare("UPDATE user_mfa_email_codes SET used_at = NOW()
                   WHERE user_id = ? AND used_at IS NULL AND expires_at > NOW()")
        ->execute([$userId]);

    $code = email_mfa_generate_code(6);
    $hash = email_mfa_hash($code, $pepper);

    $expiresAt = (new DateTimeImmutable('now'))->modify("+{$ttlSeconds} seconds")->format('Y-m-d H:i:s');

    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250);

    $ins = $pdo->prepare("INSERT INTO user_mfa_email_codes (user_id, code_hash, expires_at, request_ip, request_ua)
                          VALUES (?, ?, ?, ?, ?)");
    $ins->execute([$userId, $hash, $expiresAt, $ip, $ua]);

    $subject = "Dein Authly Sicherheitscode";
    $safeUser = htmlspecialchars($username ?: $email, ENT_QUOTES, 'UTF-8');

    $html = "
      <div style='font-family:Arial,sans-serif;line-height:1.5'>
        <h2 style='margin:0 0 10px 0'>Authly – MFA Code</h2>
        <p>Hi {$safeUser},</p>
        <p>dein Sicherheitscode lautet:</p>
        <div style='font-size:26px;font-weight:700;letter-spacing:3px;padding:12px 16px;background:#f2f2f2;border-radius:12px;display:inline-block'>
          {$code}
        </div>
        <p style='margin-top:14px;color:#666'>
          Der Code ist <b>10 Minuten</b> gültig. Wenn du das nicht warst, ändere bitte dein Passwort.
        </p>
      </div>
    ";

    $text = "Authly Sicherheitscode: {$code} (10 Minuten gültig)";

    if (!authly_send_mail($email, $subject, $html, $text)) {
        throw new RuntimeException("Mailversand fehlgeschlagen.");
    }
}

/**
 * Verifiziert Email-MFA Code. Markiert passenden Code als used.
 */
function email_mfa_verify(PDO $pdo, int $userId, string $code, string $pepper): bool {
    $hash = email_mfa_hash($code, $pepper);

    $stmt = $pdo->prepare("
        SELECT id
        FROM user_mfa_email_codes
        WHERE user_id = ?
          AND code_hash = ?
          AND used_at IS NULL
          AND expires_at > NOW()
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([$userId, $hash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return false;

    $pdo->prepare("UPDATE user_mfa_email_codes SET used_at = NOW() WHERE id = ?")
        ->execute([(int)$row['id']]);

    return true;
}
