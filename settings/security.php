<?php
// ============================================================================
// AUTHLY – USER SECURITY SETTINGS (DA DINTERFACE LOOK)
// PFAD: /settings/security.php
// ----------------------------------------------------------------------------
// - Passwort ändern
// - MFA: TOTP (Authenticator) oder Email-Code (OTP)
// ============================================================================

require_once __DIR__ . '/../db/config.php';
require_once __DIR__ . '/../functions/menu.php';
require_once __DIR__ . '/../functions/icons.php';
require_once __DIR__ . '/../functions/dbfunctions.php';

require_once __DIR__ . '/../functions/mfa_totp.php';
require_once __DIR__ . '/../functions/mfa_email.php';
require_once __DIR__ . '/../functions/crypto.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: /login/");
    exit();
}

function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

// DB Handle kompatibel
$pdo = $pdo ?? ($db ?? null);
if (!$pdo) die("DB handle fehlt. Bitte /db/config.php prüfen (erwarte \$pdo oder \$db).");

$userId   = (int)($_SESSION['user_id'] ?? 0);
$userRole = (string)($_SESSION['role_id'] ?? '2');
$userName = (string)($_SESSION['username'] ?? '');

$PEPPER = defined('AUTHLY_PEPPER') ? (string)AUTHLY_PEPPER : 'CHANGE_ME_PEPPER';

$stmtUser = $pdo->prepare("SELECT id, email, username, password, mfa_enabled, mfa_method, totp_secret_enc, totp_confirmed_at
                           FROM users WHERE id = ?");
$stmtUser->execute([$userId]);
$user = $stmtUser->fetch(PDO::FETCH_ASSOC);
if (!$user) die("User nicht gefunden.");

$ok = '';
$err = '';
$action = (string)($_POST['action'] ?? '');

try {

    // ---------------------------
    // Passwort ändern
    // ---------------------------
    if ($action === 'change_password') {
        $current = (string)($_POST['current_password'] ?? '');
        $new1    = (string)($_POST['new_password'] ?? '');
        $new2    = (string)($_POST['new_password2'] ?? '');

        if ($current === '' || $new1 === '' || $new2 === '') throw new RuntimeException("Bitte alle Felder ausfüllen.");
        if (!password_verify($current, (string)$user['password'])) throw new RuntimeException("Aktuelles Passwort ist falsch.");
        if ($new1 !== $new2) throw new RuntimeException("Neues Passwort stimmt nicht überein.");
        if (strlen($new1) < 8) throw new RuntimeException("Neues Passwort muss mindestens 8 Zeichen haben.");

        $hash = password_hash($new1, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE users SET password = ?, password_changed_at = NOW() WHERE id = ?")
            ->execute([$hash, $userId]);

        $ok = "Passwort erfolgreich geändert.";
    }

    // ---------------------------
    // MFA Methode wählen (nur wenn MFA deaktiviert)
    // ---------------------------
    if ($action === 'mfa_set_method') {
        if ((int)$user['mfa_enabled'] === 1) throw new RuntimeException("Bitte MFA erst deaktivieren, um die Methode zu wechseln.");

        $method = (string)($_POST['mfa_method'] ?? 'none');
        if (!in_array($method, ['none','totp','email'], true)) throw new RuntimeException("Ungültige MFA Methode.");

        // bei Wechsel: TOTP Secret zurücksetzen (damit es nicht “rumliegt”)
        if ($method !== 'totp') {
            $pdo->prepare("UPDATE users SET totp_secret_enc = NULL, totp_confirmed_at = NULL WHERE id = ?")
                ->execute([$userId]);
        }

        $pdo->prepare("UPDATE users SET mfa_method = ?, mfa_enabled = 0 WHERE id = ?")
            ->execute([$method, $userId]);

        header("Location: /settings/security.php");
        exit();
    }

    // ---------------------------
    // TOTP Setup starten
    // ---------------------------
    if ($action === 'totp_begin') {
        $pdo->prepare("UPDATE users SET mfa_method = 'totp', mfa_enabled = 0 WHERE id = ?")->execute([$userId]);

        $secret = totp_generate_secret();
        $secretEnc = encrypt_gcm($secret);

        $pdo->prepare("UPDATE users
                       SET totp_secret_enc = ?, totp_confirmed_at = NULL
                       WHERE id = ?")
            ->execute([$secretEnc, $userId]);

        header("Location: /settings/security.php");
        exit();
    }

    // ---------------------------
    // TOTP bestätigen
    // ---------------------------
    if ($action === 'totp_confirm') {
        $code = (string)($_POST['totp_code'] ?? '');
        if ($code === '') throw new RuntimeException("Bitte TOTP Code eingeben.");
        if (empty($user['totp_secret_enc'])) throw new RuntimeException("Kein TOTP Secret vorhanden. Setup neu starten.");

        $secret = decrypt_gcm((string)$user['totp_secret_enc']);
        if (!totp_verify($secret, $code, 1, 6, 30)) throw new RuntimeException("Code ist ungültig.");

        $pdo->prepare("UPDATE users SET mfa_enabled = 1, mfa_method='totp', totp_confirmed_at = NOW() WHERE id = ?")
            ->execute([$userId]);

        // Backup Codes neu
        $pdo->prepare("DELETE FROM user_mfa_backup_codes WHERE user_id = ?")->execute([$userId]);
        $codes = backup_codes_generate(10);

        $ins = $pdo->prepare("INSERT INTO user_mfa_backup_codes (user_id, code_hash) VALUES (?, ?)");
        foreach ($codes as $c) $ins->execute([$userId, backup_code_hash($c, $PEPPER)]);

        $_SESSION['mfa_new_backup_codes'] = $codes;

        header("Location: /settings/security.php");
        exit();
    }

    // ---------------------------
    // Email MFA Setup starten: Code senden
    // ---------------------------
    if ($action === 'email_begin') {
        $pdo->prepare("UPDATE users SET mfa_method='email', mfa_enabled=0 WHERE id = ?")->execute([$userId]);

        email_mfa_issue(
            $pdo,
            $userId,
            (string)$user['email'],
            (string)$user['username'],
            $PEPPER,
            600 // 10min
        );

        $ok = "Wir haben dir einen Code per Mail geschickt. Bitte bestätigen.";
    }

    // ---------------------------
    // Email MFA bestätigen
    // ---------------------------
    if ($action === 'email_confirm') {
        $code = (string)($_POST['email_code'] ?? '');
        if ($code === '') throw new RuntimeException("Bitte E-Mail Code eingeben.");

        if (!email_mfa_verify($pdo, $userId, $code, $PEPPER)) {
            throw new RuntimeException("E-Mail Code ist ungültig oder abgelaufen.");
        }

        $pdo->prepare("UPDATE users SET mfa_enabled = 1, mfa_method = 'email' WHERE id = ?")->execute([$userId]);

        // Backup Codes neu (auch für Email-MFA sinnvoll)
        $pdo->prepare("DELETE FROM user_mfa_backup_codes WHERE user_id = ?")->execute([$userId]);
        $codes = backup_codes_generate(10);
        $ins = $pdo->prepare("INSERT INTO user_mfa_backup_codes (user_id, code_hash) VALUES (?, ?)");
        foreach ($codes as $c) $ins->execute([$userId, backup_code_hash($c, $PEPPER)]);
        $_SESSION['mfa_new_backup_codes'] = $codes;

        header("Location: /settings/security.php");
        exit();
    }

    // ---------------------------
    // Email MFA Code erneut senden
    // ---------------------------
    if ($action === 'email_resend') {
        email_mfa_issue(
            $pdo,
            $userId,
            (string)$user['email'],
            (string)$user['username'],
            $PEPPER,
            600
        );
        $ok = "Neuer Code wurde per Mail gesendet.";
    }

    // ---------------------------
    // MFA deaktivieren (mit Passwort bestätigen)
    // ---------------------------
    if ($action === 'mfa_disable') {
        $pw = (string)($_POST['current_password'] ?? '');
        if ($pw === '') throw new RuntimeException("Bitte Passwort eingeben.");
        if (!password_verify($pw, (string)$user['password'])) throw new RuntimeException("Passwort ist falsch.");

        $pdo->prepare("UPDATE users
                       SET mfa_enabled = 0, mfa_method = 'none', totp_secret_enc = NULL, totp_confirmed_at = NULL
                       WHERE id = ?")
            ->execute([$userId]);

        $pdo->prepare("DELETE FROM user_mfa_backup_codes WHERE user_id = ?")->execute([$userId]);
        $pdo->prepare("DELETE FROM user_trusted_devices WHERE user_id = ?")->execute([$userId]);

        // Email MFA Codes löschen
        $pdo->prepare("DELETE FROM user_mfa_email_codes WHERE user_id = ?")->execute([$userId]);

        header("Location: /settings/security.php");
        exit();
    }

} catch (Throwable $e) {
    $err = $e->getMessage();
}

// Reload user
$stmtUser->execute([$userId]);
$user = $stmtUser->fetch(PDO::FETCH_ASSOC);

// Prepare TOTP otpauth if exists
$totpSecret = null;
$otpauth = null;
if (!empty($user['totp_secret_enc'])) {
    try {
        $totpSecret = decrypt_gcm((string)$user['totp_secret_enc']);
        $issuer = 'Authly';
        $label  = rawurlencode($issuer . ':' . (string)$user['email']);
        $issuerQ = rawurlencode($issuer);
        $secretQ = rawurlencode($totpSecret);
        $otpauth = "otpauth://totp/{$label}?secret={$secretQ}&issuer={$issuerQ}&digits=6&period=30";
    } catch (Throwable $e) {
        $totpSecret = null;
        $otpauth = null;
    }
}

$newBackupCodes = $_SESSION['mfa_new_backup_codes'] ?? null;
unset($_SESSION['mfa_new_backup_codes']);

?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title>Security – Settings</title>

  <script src="https://cdn.tailwindcss.com"></script>
  <link href="/gui/css/style.css" rel="stylesheet">
  <link href="/gui/css/sidebar.css" rel="stylesheet">
  <link href="/gui/css/hover.css" rel="stylesheet">
  <link href="/gui/css/project-settings.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
</head>

<body class="bg-gray-900 text-gray-200 font-inter flex">

  <aside class="sidebar fixed left-0 top-0 h-screen w-64 flex flex-col bg-gray-950/70 backdrop-blur-lg border border-gray-800/50 rounded-3xl m-4 shadow-xl transition-all duration-300">
    <div class="flex items-center justify-between px-6 py-4 border-b border-gray-800/50">
      <div class="flex items-center gap-2">
        <svg class="w-6 h-6 text-violet-400" viewBox="0 0 24 24"><path d="M12 2L20 20H4L12 2Z" /></svg>
        <span class="text-gray-100 font-semibold text-lg tracking-wide">Authly</span>
      </div>
    </div>

    <nav class="flex-1 px-2 mt-4">
      <?php echo render_menu_dynamic($_SERVER['REQUEST_URI'], (string)$userRole); ?>
    </nav>

    <div class="mt-auto px-4 py-3 border-t border-gray-800/50">
      <div class="flex items-center justify-between text-gray-400 text-xs">
        <span>© <?= date('Y'); ?> Authly</span>
        <a href="/logout.php" class="hover:text-violet-400 transition">Logout</a>
      </div>
    </div>
  </aside>

  <main class="flex-1 ml-72 p-8 transition-all">

    <header class="flex justify-between items-center mb-8">
      <div>
        <h1 class="text-2xl font-bold text-gray-100">Security</h1>
        <p class="text-gray-400 text-sm mt-1">Passwort ändern & MFA (TOTP oder Email-Code)</p>
      </div>
      <div class="text-right">
        <div class="text-gray-200 font-semibold"><?= h((string)$user['username']) ?></div>
        <div class="text-gray-500 text-xs"><?= h((string)$user['email']) ?></div>
      </div>
    </header>

    <?php if ($ok): ?>
      <div class="mb-6 p-4 rounded-2xl border border-green-500/30 bg-green-500/10 text-green-200">✅ <?= h($ok) ?></div>
    <?php endif; ?>
    <?php if ($err): ?>
      <div class="mb-6 p-4 rounded-2xl border border-red-500/30 bg-red-500/10 text-red-200">❌ <?= h($err) ?></div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

      <!-- Passwort -->
      <section class="rounded-3xl border border-gray-800/60 bg-gray-950/50 backdrop-blur-lg p-6 shadow-xl">
        <h2 class="text-lg font-semibold text-gray-100 mb-1">Passwort ändern</h2>
        <p class="text-sm text-gray-400 mb-6">Mindestlänge: 8 Zeichen.</p>

        <form method="post" autocomplete="off" class="space-y-4">
          <input type="hidden" name="action" value="change_password">

          <div>
            <label class="block text-sm text-gray-300 mb-2">Aktuelles Passwort</label>
            <input class="w-full px-4 py-3 rounded-2xl bg-gray-900/60 border border-gray-800 text-gray-100 focus:outline-none focus:ring-2 focus:ring-violet-500/40"
                   type="password" name="current_password" required>
          </div>

          <div>
            <label class="block text-sm text-gray-300 mb-2">Neues Passwort</label>
            <input class="w-full px-4 py-3 rounded-2xl bg-gray-900/60 border border-gray-800 text-gray-100 focus:outline-none focus:ring-2 focus:ring-violet-500/40"
                   type="password" name="new_password" minlength="8" required>
          </div>

          <div>
            <label class="block text-sm text-gray-300 mb-2">Neues Passwort wiederholen</label>
            <input class="w-full px-4 py-3 rounded-2xl bg-gray-900/60 border border-gray-800 text-gray-100 focus:outline-none focus:ring-2 focus:ring-violet-500/40"
                   type="password" name="new_password2" minlength="8" required>
          </div>

          <button class="px-5 py-3 rounded-2xl bg-violet-600 hover:bg-violet-500 transition text-white font-semibold shadow-lg shadow-violet-600/20"
                  type="submit">
            Speichern
          </button>
        </form>
      </section>

      <!-- MFA -->
      <section class="rounded-3xl border border-gray-800/60 bg-gray-950/50 backdrop-blur-lg p-6 shadow-xl">
        <h2 class="text-lg font-semibold text-gray-100 mb-1">MFA</h2>
        <p class="text-sm text-gray-400 mb-6">Wähle: Authenticator (TOTP) oder Email-Code.</p>

        <?php if ((int)$user['mfa_enabled'] === 1): ?>
          <div class="p-4 rounded-2xl border border-green-500/30 bg-green-500/10 text-green-200 mb-5">
            ✅ MFA aktiv (Methode: <b><?= h((string)$user['mfa_method']) ?></b>)
          </div>

          <form method="post" class="space-y-4" autocomplete="off">
            <input type="hidden" name="action" value="mfa_disable">
            <div>
              <label class="block text-sm text-gray-300 mb-2">Passwort bestätigen</label>
              <input class="w-full px-4 py-3 rounded-2xl bg-gray-900/60 border border-gray-800 text-gray-100 focus:outline-none focus:ring-2 focus:ring-violet-500/40"
                     type="password" name="current_password" required>
            </div>
            <button class="px-5 py-3 rounded-2xl bg-red-600 hover:bg-red-500 transition text-white font-semibold shadow-lg shadow-red-600/20"
                    type="submit">
              MFA deaktivieren
            </button>
          </form>

        <?php else: ?>

          <div class="p-4 rounded-2xl border border-yellow-500/30 bg-yellow-500/10 text-yellow-200 mb-5">
            ⚠️ MFA deaktiviert
          </div>

          <!-- Methode wählen -->
          <form method="post" class="mb-6">
            <input type="hidden" name="action" value="mfa_set_method">
            <label class="block text-sm text-gray-300 mb-2">MFA Methode</label>
            <select name="mfa_method"
                    class="w-full px-4 py-3 rounded-2xl bg-gray-900/60 border border-gray-800 text-gray-100 focus:outline-none focus:ring-2 focus:ring-violet-500/40">
              <option value="none"  <?= ((string)$user['mfa_method'] === 'none') ? 'selected' : '' ?>>Keine</option>
              <option value="totp"  <?= ((string)$user['mfa_method'] === 'totp') ? 'selected' : '' ?>>Authenticator (TOTP)</option>
              <option value="email" <?= ((string)$user['mfa_method'] === 'email') ? 'selected' : '' ?>>Email-Code</option>
            </select>

            <button class="mt-4 px-5 py-3 rounded-2xl bg-violet-600 hover:bg-violet-500 transition text-white font-semibold shadow-lg shadow-violet-600/20"
                    type="submit">
              Speichern
            </button>
          </form>

          <?php if ((string)$user['mfa_method'] === 'totp'): ?>
            <!-- TOTP Setup -->
            <?php if (!$totpSecret): ?>
              <form method="post">
                <input type="hidden" name="action" value="totp_begin">
                <button class="px-5 py-3 rounded-2xl bg-violet-600 hover:bg-violet-500 transition text-white font-semibold shadow-lg shadow-violet-600/20"
                        type="submit">
                  TOTP Setup starten
                </button>
              </form>
            <?php else: ?>
              <div class="space-y-4">
                <div class="p-4 rounded-2xl border border-gray-800 bg-gray-900/40">
                  <div class="text-sm text-gray-300 font-semibold mb-2">Secret</div>
                  <div class="font-mono text-lg text-gray-100 break-all"><?= h($totpSecret) ?></div>
                  <div class="text-xs text-gray-500 mt-3">OTPAUTH</div>
                  <div class="font-mono text-xs text-gray-300 break-all"><?= h($otpauth ?? '') ?></div>
                </div>

                <form method="post" autocomplete="off" class="space-y-4">
                  <input type="hidden" name="action" value="totp_confirm">
                  <div>
                    <label class="block text-sm text-gray-300 mb-2">6-stelliger Code</label>
                    <input class="w-full px-4 py-3 rounded-2xl bg-gray-900/60 border border-gray-800 text-gray-100 focus:outline-none focus:ring-2 focus:ring-violet-500/40"
                           type="text" name="totp_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required>
                  </div>
                  <button class="px-5 py-3 rounded-2xl bg-green-600 hover:bg-green-500 transition text-white font-semibold shadow-lg shadow-green-600/20"
                          type="submit">
                    MFA aktivieren
                  </button>
                </form>
              </div>
            <?php endif; ?>
          <?php endif; ?>

          <?php if ((string)$user['mfa_method'] === 'email'): ?>
            <!-- Email MFA Setup -->
            <div class="space-y-4">
              <form method="post">
                <input type="hidden" name="action" value="email_begin">
                <button class="px-5 py-3 rounded-2xl bg-violet-600 hover:bg-violet-500 transition text-white font-semibold shadow-lg shadow-violet-600/20"
                        type="submit">
                  Code per Mail senden
                </button>
              </form>

              <form method="post" autocomplete="off" class="space-y-4">
                <input type="hidden" name="action" value="email_confirm">
                <div>
                  <label class="block text-sm text-gray-300 mb-2">Email-Code</label>
                  <input class="w-full px-4 py-3 rounded-2xl bg-gray-900/60 border border-gray-800 text-gray-100 focus:outline-none focus:ring-2 focus:ring-violet-500/40"
                         type="text" name="email_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required>
                </div>

                <div class="flex gap-3">
                  <button class="px-5 py-3 rounded-2xl bg-green-600 hover:bg-green-500 transition text-white font-semibold shadow-lg shadow-green-600/20"
                          type="submit">
                    MFA aktivieren
                  </button>
                  <button class="px-5 py-3 rounded-2xl bg-gray-700 hover:bg-gray-600 transition text-white font-semibold"
                          type="submit" name="action" value="email_resend">
                    Neu senden
                  </button>
                </div>
              </form>

              <div class="text-xs text-gray-500">
                Code ist 10 Minuten gültig. (Wenn du „Neu senden“ nutzt, wird der alte Code ungültig gemacht.)
              </div>
            </div>
          <?php endif; ?>

        <?php endif; ?>

        <?php if (is_array($newBackupCodes) && count($newBackupCodes) > 0): ?>
          <div class="mt-6 p-4 rounded-2xl border border-green-500/30 bg-green-500/10">
            <div class="text-gray-100 font-semibold mb-2">✅ Backup Codes (JETZT speichern!)</div>
            <div class="text-sm text-gray-300 mb-4">Jeder Code ist einmal nutzbar. Sie werden nur einmal angezeigt.</div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <?php foreach ($newBackupCodes as $c): ?>
                <div class="font-mono text-base px-3 py-2 rounded-xl bg-gray-950/60 border border-gray-800 text-gray-100">
                  <?= h($c) ?>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

      </section>

    </div>
  </main>
</body>
</html>
