<?php
// ============================================================================
// Authly Installer – Abschluss der Installation
// PFAD: /install/finish.php
// ============================================================================

session_start();

// Lockfile-Pfad
$lockFile = __DIR__ . '/install.lock';

// Config laden
require_once __DIR__ . '/../db/config.php';

// --- Helper: Encrypt (reversible) for SMTP password ---
function authly_encrypt(string $plain, string $masterKey): string {
    if ($plain === '') return '';
    $key = hash('sha256', $masterKey, true);
    $iv = random_bytes(16);
    $cipher = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    if ($cipher === false) return '';
    return base64_encode($iv . $cipher);
}

// --- Helper: Inject/Replace define() lines in db/config.php ---
function upsert_define(string $file, string $name, string $valueLiteral): bool {
    $content = file_get_contents($file);
    if ($content === false) return false;

    $pattern = "/^\\s*define\\(\\s*'" . preg_quote($name, '/') . "'\\s*,\\s*.*?\\)\\s*;\\s*$/m";
    $line = "define('{$name}', {$valueLiteral});";

    if (preg_match($pattern, $content)) {
        $content = preg_replace($pattern, $line, $content);
    } else {
        // Insert before closing PHP tag if present, else append
        if (preg_match('/\\?>\\s*$/', $content)) {
            $content = preg_replace('/\\?>\\s*$/', "\n{$line}\n?>", $content);
        } else {
            $content .= "\n{$line}\n";
        }
    }
    return file_put_contents($file, $content) !== false;
}

$configFile = __DIR__ . '/../db/config.php';

// Mail config from installer (optional)
$mail = $_SESSION['install_mail'] ?? ['enabled' => 0];

// Persist mail defines (only if installer reached this point)
// Always write MAIL_ENABLED so the app can check.
upsert_define($configFile, 'MAIL_ENABLED', ((int)($mail['enabled'] ?? 0) === 1) ? 'true' : 'false');

if ((int)($mail['enabled'] ?? 0) === 1) {
    $host      = (string)($mail['host'] ?? '');
    $port      = (int)($mail['port'] ?? 587);
    $user      = (string)($mail['username'] ?? '');
    $passPlain = (string)($mail['password'] ?? '');
    $fromMail  = (string)($mail['from_mail'] ?? '');
    $fromName  = (string)($mail['from_name'] ?? 'Authly');

    $passEnc = authly_encrypt($passPlain, defined('AUTHLY_KEY') ? AUTHLY_KEY : '');

    upsert_define($configFile, 'MAIL_SMTP_HOST', var_export($host, true));
    upsert_define($configFile, 'MAIL_SMTP_PORT', (string)$port);
    upsert_define($configFile, 'MAIL_SMTP_USER', var_export($user, true));
    upsert_define($configFile, 'MAIL_SMTP_PASS_ENC', var_export($passEnc, true));
    upsert_define($configFile, 'MAIL_FROM_MAIL', var_export($fromMail, true));
    upsert_define($configFile, 'MAIL_FROM_NAME', var_export($fromName, true));
}

// Prüfen, ob bereits vorhanden
if (file_exists($lockFile)) {
    $alreadyInstalled = true;
} else {
    // Lockfile erstellen
    $content = "Authly installiert am: " . date('Y-m-d H:i:s') . "\n";
    $content .= "Server: " . ($_SERVER['SERVER_NAME'] ?? 'localhost') . "\n";
    $content .= "Pfad: " . __DIR__ . "\n";
    file_put_contents($lockFile, $content);
    $alreadyInstalled = false;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Authly Setup – Installation abgeschlossen</title>
<link rel="stylesheet" href="../gui/css/install.css">
<style>
h1{margin-bottom:15px}
p{margin-bottom:20px}
.success-box{background:rgba(255,255,255,0.1);padding:30px 50px;border-radius:15px;box-shadow:0 0 20px rgba(0,0,0,0.2)}
footer{position:absolute;bottom:25px;font-size:.9rem;opacity:.7}
a.login-btn{background:#fff;color:#4f46e5;padding:12px 30px;border-radius:10px;font-weight:600;text-decoration:none;transition:all .3s ease}
a.login-btn:hover{background:#f1f1f1;transform:scale(1.05)}
</style>
</head>
<body>

<div class="setup-box">
    <h1>🎉 Installation abgeschlossen!</h1>

    <div class="success-box">
        <?php if ($alreadyInstalled): ?>
            <p>Authly war bereits installiert.<br>Du kannst dich jetzt direkt anmelden.</p>
        <?php else: ?>
            <p>✅ <strong>Authly</strong> wurde erfolgreich installiert und ist nun einsatzbereit!</p>
            <p>Die Datei <strong>install.lock</strong> wurde erstellt, um eine erneute Installation zu verhindern.</p>
            <p>Mail-Konfiguration: <strong><?= (defined('MAIL_ENABLED') && MAIL_ENABLED) ? 'aktiv' : 'übersprungen' ?></strong></p>
        <?php endif; ?>

        <p>Du kannst dich jetzt mit deinem Administrator-Account anmelden.</p>

        <a href="../login/" class="login-btn">Zum Login</a>
    </div>
</div>

<footer>© <?php echo date('Y'); ?> Authly Installer</footer>

</body>
</html>
