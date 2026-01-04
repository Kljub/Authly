<?php
// ============================================================================
// PFAD: /functions/email_change.php
// ----------------------------------------------------------------------------
// Email ändern: Code an ALTE Email senden, dann erst Update durchführen
// ============================================================================

require_once __DIR__ . '/mfa_email.php'; // nutzt authly_send_mail()

function email_change_code_hash(string $code, string $pepper): string {
    $norm = preg_replace('/\s+/', '', (string)$code);
    return hash('sha256', $pepper . '|email_change|' . $norm);
}

function email_change_generate_code(int $digits = 6): string {
    $min = 10 ** ($digits - 1);
    $max = (10 ** $digits) - 1;
    return (string)random_int($min, $max);
}

/**
 * Startet Email-Change: invalidiert alte Requests, speichert new_email + hash, sendet Code an OLD email.
 */
function email_change_begin(PDO $pdo, int $userId, string $oldEmail, string $newEmail, string $username, string $pepper, int $ttlSeconds = 900): void {
    $newEmail = strtolower(trim($newEmail));
    $oldEmail = strtolower(trim($oldEmail));

    if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException("Neue E-Mail ist ungültig.");
    }
    if ($newEmail === $oldEmail) {
        throw new RuntimeException("Neue E-Mail muss sich von der alten unterscheiden.");
    }

    // E-Mail bereits vergeben?
    $chk = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $chk->execute([$newEmail]);
    $exists = $chk->fetch(PDO::FETCH_ASSOC);
    if ($exists) {
        throw new RuntimeException("Diese E-Mail ist bereits vergeben.");
    }

    // Alte Requests invalidieren (nur für diesen User)
    $pdo->prepare("UPDATE user_email_change_requests
                   SET used_at = NOW()
                   WHERE user_id = ? AND used_at IS NULL AND expires_at > NOW()")
        ->execute([$userId]);

    $code = email_change_generate_code(6);
    $hash = email_change_code_hash($code, $pepper);

    $expiresAt = (new DateTimeImmutable('now'))->modify("+{$ttlSeconds} seconds")->format('Y-m-d H:i:s');

    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250);

    $ins = $pdo->prepare("INSERT INTO user_email_change_requests
        (user_id, old_email, new_email, code_hash, expires_at, request_ip, request_ua)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $ins->execute([$userId, $oldEmail, $newEmail, $hash, $expiresAt, $ip, $ua]);

    $safeUser = htmlspecialchars($username ?: $oldEmail, ENT_QUOTES, 'UTF-8');

    $subject = "Authly – E-Mail Änderung bestätigen";
    $html = "
      <div style='font-family:Arial,sans-serif;line-height:1.5'>
        <h2 style='margin:0 0 10px 0'>Authly – Bestätigungscode</h2>
        <p>Hi {$safeUser},</p>
        <p>du möchtest deine E-Mail-Adresse ändern.</p>
        <p>Bestätige den Vorgang mit diesem Code:</p>
        <div style='font-size:26px;font-weight:700;letter-spacing:3px;padding:12px 16px;background:#f2f2f2;border-radius:12px;display:inline-block'>
          {$code}
        </div>
        <p style='margin-top:14px;color:#666'>
          Der Code ist <b>15 Minuten</b> gültig. Wenn du das nicht warst, ändere bitte dein Passwort.
        </p>
      </div>
    ";
    $text = "Authly Code zur Email-Änderung: {$code} (15 Minuten gültig)";

    if (!authly_send_mail($oldEmail, $subject, $html, $text)) {
        throw new RuntimeException("Mailversand an alte E-Mail fehlgeschlagen.");
    }
}

/**
 * Verifiziert Code und führt Email-Update durch.
 * Optional sendet es eine Info-Mail an die neue Email.
 */
function email_change_confirm(PDO $pdo, int $userId, string $code, string $pepper, bool $notifyNewEmail = true): void {
    $hash = email_change_code_hash($code, $pepper);

    $stmt = $pdo->prepare("
        SELECT id, old_email, new_email
        FROM user_email_change_requests
        WHERE user_id = ?
          AND code_hash = ?
          AND used_at IS NULL
          AND expires_at > NOW()
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([$userId, $hash]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$req) {
        throw new RuntimeException("Code ist ungültig oder abgelaufen.");
    }

    // Safety: new_email nochmal prüfen (kann in der Zwischenzeit vergeben worden sein)
    $chk = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $chk->execute([(string)$req['new_email']]);
    $exists = $chk->fetch(PDO::FETCH_ASSOC);
    if ($exists) {
        throw new RuntimeException("Diese E-Mail ist inzwischen vergeben. Bitte erneut versuchen.");
    }

    // Update durchführen
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE users SET email = ? WHERE id = ?")
            ->execute([(string)$req['new_email'], $userId]);

        $pdo->prepare("UPDATE user_email_change_requests SET used_at = NOW() WHERE id = ?")
            ->execute([(int)$req['id']]);

        // Optional: alle anderen offenen Requests ebenfalls schließen
        $pdo->prepare("UPDATE user_email_change_requests
                       SET used_at = NOW()
                       WHERE user_id = ? AND used_at IS NULL")
            ->execute([$userId]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    // Info-Mail an neue Email (optional)
    if ($notifyNewEmail) {
        $subject = "Authly – E-Mail erfolgreich geändert";
        $html = "
          <div style='font-family:Arial,sans-serif;line-height:1.5'>
            <h2 style='margin:0 0 10px 0'>Authly</h2>
            <p>Deine E-Mail-Adresse wurde erfolgreich geändert.</p>
            <p style='color:#666'>Wenn du das nicht warst, kontaktiere bitte sofort einen Admin.</p>
          </div>
        ";
        $text = "Authly: Deine E-Mail-Adresse wurde erfolgreich geändert.";
        @authly_send_mail((string)$req['new_email'], $subject, $html, $text);
    }
}
