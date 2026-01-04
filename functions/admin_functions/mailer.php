<?php
// ============================================================================
// PFAD: /functions/admin_functions/mailer.php
// ----------------------------------------------------------------------------
// AUTHLY – Admin Mailer Functions (DB + SMTP Test)
// - settings_get / settings_upsert (settings Tabelle)
// - get_user_email
// - log_activity
// - SMTP lowlevel Testversand (ohne externe Libs)
// ============================================================================

/**
 * Settings aus settings Tabelle lesen
 */
function settings_get(PDO $pdo, string $key, $default = null) {
    $st = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = :k LIMIT 1");
    $st->execute([':k' => $key]);
    $v = $st->fetchColumn();
    return ($v === false || $v === null) ? $default : $v;
}

/**
 * Settings upsert in settings Tabelle
 * @param array $data key => value
 */
function settings_upsert(PDO $pdo, array $data): void {
    $st = $pdo->prepare("
        INSERT INTO settings (setting_key, setting_value, updated_at)
        VALUES (:k, :v, CURRENT_TIMESTAMP)
        ON DUPLICATE KEY UPDATE
            setting_value = VALUES(setting_value),
            updated_at = CURRENT_TIMESTAMP
    ");
    foreach ($data as $k => $v) {
        if ($v === '') $v = null;
        $st->execute([':k' => $k, ':v' => $v]);
    }
}

/**
 * User E-Mail holen (users Tabelle)
 */
function get_user_email(PDO $pdo, int $uid): string {
    try {
        $st = $pdo->prepare("SELECT email FROM users WHERE id = :id LIMIT 1");
        $st->execute([':id' => $uid]);
        $email = trim((string)($st->fetchColumn() ?: ''));
        return (filter_var($email, FILTER_VALIDATE_EMAIL)) ? $email : '';
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * Activity Log (activity_logs Tabelle)
 */
function log_activity(PDO $pdo, ?int $userId, ?int $projectId, string $action, ?string $details = null): void {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $st = $pdo->prepare("
            INSERT INTO activity_logs (user_id, project_id, action, details, ip, created_at)
            VALUES (:uid, :pid, :a, :d, :ip, CURRENT_TIMESTAMP)
        ");
        $st->execute([
            ':uid' => $userId,
            ':pid' => $projectId,
            ':a'   => $action,
            ':d'   => $details,
            ':ip'  => $ip
        ]);
    } catch (Throwable $e) {
        // silent
    }
}

// -------------------------
// SMTP Lowlevel Helpers
// -------------------------
function smtp_read($fp): string {
    $data = '';
    while (!feof($fp)) {
        $line = fgets($fp, 515);
        if ($line === false) break;
        $data .= $line;
        // endet bei "xyz<space>"
        if (preg_match('/^\d{3}\s/', $line)) break;
    }
    return $data;
}

function smtp_send($fp, string $cmd): void {
    fwrite($fp, $cmd . "\r\n");
}

function smtp_supports_starttls(string $ehloResp): bool {
    return (stripos($ehloResp, 'STARTTLS') !== false);
}

/**
 * SMTP Testversand (AUTH LOGIN) + STARTTLS/465 implicit TLS
 * @return true|string  true bei Erfolg, ansonsten Fehlermeldung
 */
function smtp_test_send(array $cfg, string $toEmail) {
    $host = trim((string)($cfg['smtp_host'] ?? ''));
    $port = (int)($cfg['smtp_port'] ?? 0);
    $user = (string)($cfg['smtp_user'] ?? '');
    $pass = (string)($cfg['smtp_pass'] ?? '');
    $from = trim((string)($cfg['from_mail'] ?? ''));
    $fromName = trim((string)($cfg['from_name'] ?? 'Authly'));

    if ($host === '' || $port <= 0 || $user === '' || $pass === '' || $from === '') {
        return "Bitte SMTP Host/Port/User/Passwort sowie Absender-E-Mail ausfüllen.";
    }
    if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return "Empfänger-E-Mail ist nicht gesetzt oder ungültig.";
    }

    // implicit TLS
    $connectHost = $host;
    if ($port === 465 && stripos($host, 'ssl://') !== 0) {
        $connectHost = 'ssl://' . $host;
    }

    $fp = @fsockopen($connectHost, $port, $errno, $errstr, 12);
    if (!$fp) return "SMTP Verbindung fehlgeschlagen: $errstr ($errno)";
    stream_set_timeout($fp, 12);

    $banner = smtp_read($fp);
    if (!preg_match('/^220\b/m', $banner)) {
        fclose($fp);
        return "SMTP Banner ungültig/unerwartet: " . trim($banner);
    }

    smtp_send($fp, "EHLO authly-admin");
    $ehlo = smtp_read($fp);
    if (!preg_match('/^250\b/m', $ehlo)) {
        fclose($fp);
        return "EHLO fehlgeschlagen: " . trim($ehlo);
    }

    $isImplicitTls = (stripos($connectHost, 'ssl://') === 0);

    // STARTTLS wenn möglich + nicht implicit TLS
    if (!$isImplicitTls && smtp_supports_starttls($ehlo) && function_exists('stream_socket_enable_crypto')) {
        smtp_send($fp, "STARTTLS");
        $resp = smtp_read($fp);
        if (preg_match('/^220\b/m', $resp)) {
            $cryptoOk = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if (!$cryptoOk) {
                fclose($fp);
                return "STARTTLS konnte nicht aktiviert werden (TLS Handshake fehlgeschlagen).";
            }
            smtp_send($fp, "EHLO authly-admin");
            $ehlo2 = smtp_read($fp);
            if (!preg_match('/^250\b/m', $ehlo2)) {
                fclose($fp);
                return "EHLO nach STARTTLS fehlgeschlagen: " . trim($ehlo2);
            }
        }
    }

    // AUTH LOGIN
    smtp_send($fp, "AUTH LOGIN");
    $resp = smtp_read($fp);
    if (!preg_match('/^334\b/m', $resp)) {
        fclose($fp);
        return "AUTH LOGIN nicht akzeptiert: " . trim($resp);
    }

    smtp_send($fp, base64_encode($user));
    $resp = smtp_read($fp);
    if (!preg_match('/^334\b/m', $resp)) {
        fclose($fp);
        return "SMTP Username wurde nicht akzeptiert: " . trim($resp);
    }

    smtp_send($fp, base64_encode($pass));
    $resp = smtp_read($fp);
    if (!preg_match('/^235\b/m', $resp)) {
        fclose($fp);
        return "SMTP Auth fehlgeschlagen (Passwort/User falsch?): " . trim($resp);
    }

    // Envelope
    smtp_send($fp, "MAIL FROM:<{$from}>");
    $resp = smtp_read($fp);
    if (!preg_match('/^250\b/m', $resp)) {
        fclose($fp);
        return "MAIL FROM fehlgeschlagen: " . trim($resp);
    }

    smtp_send($fp, "RCPT TO:<{$toEmail}>");
    $resp = smtp_read($fp);
    if (!preg_match('/^250\b/m', $resp) && !preg_match('/^251\b/m', $resp)) {
        fclose($fp);
        return "RCPT TO fehlgeschlagen: " . trim($resp);
    }

    smtp_send($fp, "DATA");
    $resp = smtp_read($fp);
    if (!preg_match('/^354\b/m', $resp)) {
        fclose($fp);
        return "DATA wurde nicht akzeptiert: " . trim($resp);
    }

    $subject = "Willkommen bei Authly – SMTP Test erfolgreich";
    $body =
        "Willkommen bei Authly,\r\n\r\n" .
        "diese E-Mail wurde erfolgreich über die im Admin-Panel\r\n" .
        "konfigurierte SMTP-Verbindung versendet.\r\n\r\n" .
        "Empfänger:\r\n" . $toEmail . "\r\n\r\n" .
        "Zeitpunkt:\r\n" . date('d.m.Y H:i:s') . "\r\n\r\n" .
        "--\r\nAuthly Admin";

    // Headers
    smtp_send($fp, "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=");
    smtp_send($fp, "From: " . ($fromName !== '' ? "{$fromName} <{$from}>" : "<{$from}>"));
    smtp_send($fp, "To: <{$toEmail}>");
    smtp_send($fp, "MIME-Version: 1.0");
    smtp_send($fp, "Content-Type: text/plain; charset=UTF-8");
    smtp_send($fp, "Content-Transfer-Encoding: 8bit");
    smtp_send($fp, "");

    // Dot-stuffing
    $lines = preg_split("/\r\n|\n|\r/", $body);
    foreach ($lines as $line) {
        if ($line !== '' && isset($line[0]) && $line[0] === '.') $line = '.' . $line;
        smtp_send($fp, $line);
    }

    smtp_send($fp, ".");
    $resp = smtp_read($fp);
    if (!preg_match('/^250\b/m', $resp)) {
        fclose($fp);
        return "Server hat Nachricht nicht angenommen: " . trim($resp);
    }

    smtp_send($fp, "QUIT");
    fclose($fp);

    return true;
}
