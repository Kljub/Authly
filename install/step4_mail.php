<?php
// ============================================================================
// Authly Installer – Schritt 4 : Mail (optional + Testmail + DB Save)
// PFAD: /install/step4_mail.php
// ============================================================================

$lockFile = __DIR__ . '/install.lock';
if (file_exists($lockFile)) {
    die('<h2 style="color:red;text-align:center;margin-top:50px;">
        🚫 Authly ist bereits installiert.<br><br>
        <a href="../login/" style="color:#4f46e5;text-decoration:none;font-weight:bold;">→ Zum Login</a>
    </h2>');
}

session_start();

// ---------------------------------------------------------------------------
// DB Config laden (aus /db/config.php)
// ---------------------------------------------------------------------------
function get_pdo_from_config(): ?PDO
{
    $configPath = __DIR__ . '/../db/config.php';
    if (!file_exists($configPath)) return null;

    require_once $configPath;

    if (!defined('DB_HOST') || !defined('DB_NAME') || !defined('DB_USER') || !defined('DB_PASS')) {
        return null;
    }

    $host = DB_HOST;
    $name = DB_NAME;
    $user = DB_USER;
    $pass = DB_PASS;
    $charset = defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4';

    try {
        return new PDO(
            "mysql:host={$host};dbname={$name};charset={$charset}",
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    } catch (Throwable $e) {
        return null;
    }
}

// ---------------------------------------------------------------------------
// Settings Upsert in DB
// ---------------------------------------------------------------------------
function settings_upsert(PDO $pdo, array $data): void
{
    $stmt = $pdo->prepare("
        INSERT INTO settings (setting_key, setting_value, updated_at)
        VALUES (:k, :v, CURRENT_TIMESTAMP)
        ON DUPLICATE KEY UPDATE
            setting_value = VALUES(setting_value),
            updated_at = CURRENT_TIMESTAMP
    ");

    foreach ($data as $k => $v) {
        if ($v === '') $v = null;
        $stmt->execute([':k' => $k, ':v' => $v]);
    }
}

// ---------------------------------------------------------------------------
// Admin E-Mail aus DB users.id = 1
// ---------------------------------------------------------------------------
function get_admin_email(PDO $pdo): string
{
    try {
        $row = $pdo->query("SELECT email FROM users WHERE id = 1 LIMIT 1")->fetch();
        $email = isset($row['email']) ? trim((string)$row['email']) : '';
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) return $email;
    } catch (Throwable $e) {}
    return '';
}

// ---------------------------------------------------------------------------
// SMTP helpers
// ---------------------------------------------------------------------------
function smtp_read($fp): string
{
    $data = '';
    while (!feof($fp)) {
        $line = fgets($fp, 515);
        if ($line === false) break;
        $data .= $line;
        if (preg_match('/^\d{3}\s/', $line)) break;
    }
    return $data;
}
function smtp_send($fp, string $cmd): void
{
    fwrite($fp, $cmd . "\r\n");
}
function smtp_supports_starttls(string $ehloResp): bool
{
    return (stripos($ehloResp, 'STARTTLS') !== false);
}

// ---------------------------------------------------------------------------
// SMTP Testversand (AUTH LOGIN) + STARTTLS/465 implicit TLS
// ---------------------------------------------------------------------------
function smtp_test_send(array $cfg, string $toEmail): bool|string
{
    $host = trim($cfg['smtp_host'] ?? '');
    $port = (int)($cfg['smtp_port'] ?? 0);
    $user = (string)($cfg['smtp_user'] ?? '');
    $pass = (string)($cfg['smtp_pass'] ?? '');
    $from = trim($cfg['from_mail'] ?? '');
    $fromName = trim($cfg['from_name'] ?? 'Authly');

    if ($host === '' || $port <= 0 || $user === '' || $pass === '' || $from === '') {
        return "Bitte SMTP Host/Port/User/Passwort sowie Absender-E-Mail ausfüllen.";
    }
    if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return "Empfänger (Admin E-Mail) ist nicht gesetzt oder ungültig.";
    }

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

    smtp_send($fp, "EHLO authly-installer");
    $ehlo = smtp_read($fp);
    if (!preg_match('/^250\b/m', $ehlo)) {
        fclose($fp);
        return "EHLO fehlgeschlagen: " . trim($ehlo);
    }

    $isImplicitTls = (stripos($connectHost, 'ssl://') === 0);
    if (!$isImplicitTls && smtp_supports_starttls($ehlo) && function_exists('stream_socket_enable_crypto')) {
        smtp_send($fp, "STARTTLS");
        $resp = smtp_read($fp);
        if (preg_match('/^220\b/m', $resp)) {
            $cryptoOk = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if (!$cryptoOk) {
                fclose($fp);
                return "STARTTLS konnte nicht aktiviert werden (TLS Handshake fehlgeschlagen).";
            }
            smtp_send($fp, "EHLO authly-installer");
            $ehlo2 = smtp_read($fp);
            if (!preg_match('/^250\b/m', $ehlo2)) {
                fclose($fp);
                return "EHLO nach STARTTLS fehlgeschlagen: " . trim($ehlo2);
            }
        }
    }

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
        "diese E-Mail wurde erfolgreich über die im Installer\r\n" .
        "konfigurierte SMTP-Verbindung versendet.\r\n\r\n" .
        "Empfänger:\r\n" . $toEmail . "\r\n\r\n" .
        "Zeitpunkt:\r\n" . date('d.m.Y H:i:s') . "\r\n\r\n" .
        "--\r\nAuthly Installer";

    smtp_send($fp, "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=");
    smtp_send($fp, "From: " . ($fromName !== '' ? "{$fromName} <{$from}>" : "<{$from}>"));
    smtp_send($fp, "To: <{$toEmail}>");
    smtp_send($fp, "MIME-Version: 1.0");
    smtp_send($fp, "Content-Type: text/plain; charset=UTF-8");
    smtp_send($fp, "Content-Transfer-Encoding: 8bit");
    smtp_send($fp, "");

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

// ---------------------------------------------------------------------------
// Init
// ---------------------------------------------------------------------------
$pdo = get_pdo_from_config();
$adminEmail = $pdo ? get_admin_email($pdo) : '';

$saved = $_SESSION['install']['mail'] ?? [];
$cfg = [
    'enabled'   => (int)($saved['enabled'] ?? 0),
    'smtp_host' => (string)($saved['smtp_host'] ?? ''),
    'smtp_port' => (string)($saved['smtp_port'] ?? ''),
    'smtp_user' => (string)($saved['smtp_user'] ?? ''),
    'smtp_pass' => (string)($saved['smtp_pass'] ?? ''),
    'from_mail' => (string)($saved['from_mail'] ?? ''),
    'from_name' => (string)($saved['from_name'] ?? ''),
];

$alertText = '';
$alertType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $cfg['enabled']   = isset($_POST['enabled']) ? 1 : 0;
    $cfg['smtp_host'] = trim((string)($_POST['smtp_host'] ?? ''));
    $cfg['smtp_port'] = trim((string)($_POST['smtp_port'] ?? ''));
    $cfg['smtp_user'] = trim((string)($_POST['smtp_user'] ?? ''));
    $cfg['smtp_pass'] = (string)($_POST['smtp_pass'] ?? '');
    $cfg['from_mail'] = trim((string)($_POST['from_mail'] ?? ''));
    $cfg['from_name'] = trim((string)($_POST['from_name'] ?? ''));

    if (isset($_POST['test_mail'])) {
        $res = smtp_test_send($cfg, $adminEmail);
        if ($res === true) {
            $alertType = 'success';
            $alertText = "✅ Test-Mail wurde erfolgreich an {$adminEmail} gesendet.";
        } else {
            $alertType = 'error';
            $alertText = "❌ Test fehlgeschlagen: " . $res;
        }
    }

    if (isset($_POST['skip'])) {
        $_SESSION['install']['mail'] = ['enabled' => 0];

        if ($pdo) {
            settings_upsert($pdo, [
                'smtp_enabled' => 0,
                'smtp_host' => null,
                'smtp_port' => null,
                'smtp_user' => null,
                'smtp_pass' => null,
                'from_mail' => null,
                'from_name' => null,
            ]);
        }

        header('Location: finish.php');
        exit;
    }

    if (isset($_POST['save'])) {
        $_SESSION['install']['mail'] = $cfg;

        if ($pdo) {
            settings_upsert($pdo, [
                'smtp_enabled' => (int)$cfg['enabled'],
                'smtp_host' => $cfg['smtp_host'],
                'smtp_port' => $cfg['smtp_port'],
                'smtp_user' => $cfg['smtp_user'],
                'smtp_pass' => $cfg['smtp_pass'],
                'from_mail' => $cfg['from_mail'],
                'from_name' => $cfg['from_name'],
            ]);
        }

        header('Location: finish.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Authly Setup – Mail konfigurieren</title>

    <!-- Installer Styles (Cache-Busting, damit Änderungen sofort greifen) -->
    <link rel="stylesheet" href="../gui/css/install.css?v=<?= time() ?>">
</head>
<body>

<div class="setup-box">
    <h1>📧 Schritt 4 – Mail (optional)</h1>

    <p>
        Hier kannst du SMTP konfigurieren.
        Du kannst den Schritt jederzeit überspringen.
    </p>

    <?php if ($alertText !== ''): ?>
        <p class="<?= $alertType === 'success' ? 'success' : 'error' ?>">
            <?= htmlspecialchars($alertText, ENT_QUOTES, 'UTF-8') ?>
        </p>
    <?php endif; ?>

    <?php if (!$pdo): ?>
        <div class="note">
            ⚠️ <b>DB Verbindung nicht möglich</b>. Test-Mail ist deaktiviert.
        </div>
    <?php elseif ($adminEmail === ''): ?>
        <div class="note">
            ⚠️ <b>Admin-E-Mail nicht gefunden</b>. Test-Mail ist deaktiviert.
        </div>
    <?php endif; ?>

    <form method="post" autocomplete="off">

        <label class="checkbox-row">
            <input type="checkbox" name="enabled" <?= ($cfg['enabled'] ? 'checked' : '') ?>>
            <span>SMTP aktivieren</span>
        </label>

        <input type="text"
               name="smtp_host"
               placeholder="SMTP Host (z. B. smtp.gmail.com)"
               value="<?= htmlspecialchars($cfg['smtp_host'], ENT_QUOTES, 'UTF-8') ?>">

        <input type="text"
               name="smtp_port"
               placeholder="Port (z. B. 587 oder 465)"
               value="<?= htmlspecialchars($cfg['smtp_port'], ENT_QUOTES, 'UTF-8') ?>">

        <input type="text"
               name="smtp_user"
               placeholder="SMTP Benutzer"
               value="<?= htmlspecialchars($cfg['smtp_user'], ENT_QUOTES, 'UTF-8') ?>">

        <input type="password"
               name="smtp_pass"
               placeholder="SMTP Passwort"
               value="<?= htmlspecialchars($cfg['smtp_pass'], ENT_QUOTES, 'UTF-8') ?>">

        <input type="text"
               name="from_mail"
               placeholder="Absender E-Mail"
               value="<?= htmlspecialchars($cfg['from_mail'], ENT_QUOTES, 'UTF-8') ?>">

        <input type="text"
               name="from_name"
               placeholder="Absender Name (optional)"
               value="<?= htmlspecialchars($cfg['from_name'], ENT_QUOTES, 'UTF-8') ?>">

        <p class="small">
            Test-Mail wird an das Admin-Konto gesendet:<br>
            <b><?= htmlspecialchars($adminEmail !== '' ? $adminEmail : '— nicht gesetzt —', ENT_QUOTES, 'UTF-8') ?></b>
        </p>

        <div class="row">
            <button type="submit"
                    name="test_mail"
                    class="btn btn-test"
                    <?= (!$pdo || $adminEmail === '' ? 'disabled' : '') ?>>
                📨 Test-Mail senden
            </button>

            <button type="submit" name="save" class="btn">
                Weiter
            </button>

            <button type="submit" name="skip" class="btn btn-secondary">
                Überspringen
            </button>
        </div>
    </form>
</div>

</body>
</html>
