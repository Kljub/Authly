<?php
// ============================================================================
// AUTHLY – USER ACCOUNT SETTINGS (DA DINTERFACE LOOK)
// PFAD: /settings/account.php
// ----------------------------------------------------------------------------
// - Username ändern
// - Email ändern (Bestätigungscode an ALTE Email)
// ============================================================================

require_once __DIR__ . '/../db/config.php';
require_once __DIR__ . '/../functions/menu.php';
require_once __DIR__ . '/../functions/icons.php';
require_once __DIR__ . '/../functions/dbfunctions.php';

require_once __DIR__ . '/../functions/mfa_email.php';
require_once __DIR__ . '/../functions/email_change.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: /login/");
    exit();
}

function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

$pdo = $pdo ?? ($db ?? null);
if (!$pdo) die("DB handle fehlt. Bitte /db/config.php prüfen (erwarte \$pdo oder \$db).");

$userId   = (int)($_SESSION['user_id'] ?? 0);
$userRole = (string)($_SESSION['role_id'] ?? '2');

$PEPPER = defined('AUTHLY_PEPPER') ? (string)AUTHLY_PEPPER : 'CHANGE_ME_PEPPER';

$stmt = $pdo->prepare("SELECT id, email, username, password FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) die("User nicht gefunden.");

$ok = '';
$err = '';
$action = (string)($_POST['action'] ?? '');

function normalize_username(string $name): string {
    $name = trim($name);
    // Mehrfach-Spaces reduzieren
    $name = preg_replace('/\s+/', ' ', $name);
    return $name;
}

try {

    // ------------------------------------------------------------
    // USERNAME ändern
    // ------------------------------------------------------------
    if ($action === 'username_change') {
        $newName = normalize_username((string)($_POST['new_username'] ?? ''));
        $pw      = (string)($_POST['password_confirm'] ?? '');

        if ($newName === '') throw new RuntimeException("Bitte einen Namen eingeben.");
        if (mb_strlen($newName) < 3) throw new RuntimeException("Name muss mindestens 3 Zeichen haben.");
        if (mb_strlen($newName) > 32) throw new RuntimeException("Name darf maximal 32 Zeichen haben.");

        // Erlaubte Zeichen: Buchstaben, Zahlen, Space, _.-  (angepasst für Authly)
        if (!preg_match('/^[\p{L}\p{N}\s_.-]+$/u', $newName)) {
            throw new RuntimeException("Name enthält ungültige Zeichen.");
        }

        // Passwort bestätigen (empfohlen)
        if ($pw === '') throw new RuntimeException("Bitte Passwort zur Bestätigung eingeben.");
        if (!password_verify($pw, (string)$user['password'])) throw new RuntimeException("Passwort ist falsch.");

        // Unique check (falls du unique willst – empfohlen)
        $chk = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1");
        $chk->execute([$newName, $userId]);
        if ($chk->fetch(PDO::FETCH_ASSOC)) {
            throw new RuntimeException("Dieser Name ist bereits vergeben.");
        }

        $pdo->prepare("UPDATE users SET username = ? WHERE id = ?")->execute([$newName, $userId]);

        // Session Username updaten (damit Sidebar/Header sofort stimmt)
        $_SESSION['username'] = $newName;

        // Reload user
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        $ok = "Name wurde erfolgreich geändert.";
    }

    // ------------------------------------------------------------
    // Start Email Change -> Code an alte Email
    // ------------------------------------------------------------
    if ($action === 'email_change_begin') {
        $newEmail = (string)($_POST['new_email'] ?? '');
        $pw       = (string)($_POST['email_password_confirm'] ?? '');

        if ($pw === '') throw new RuntimeException("Bitte Passwort zur Bestätigung eingeben.");
        if (!password_verify($pw, (string)$user['password'])) throw new RuntimeException("Passwort ist falsch.");

        email_change_begin(
            $pdo,
            $userId,
            (string)$user['email'],
            $newEmail,
            (string)$user['username'],
            $PEPPER,
            900 // 15min
        );

        $_SESSION['pending_new_email'] = strtolower(trim($newEmail));
        $ok = "Code wurde an deine alte E-Mail gesendet. Bitte bestätigen.";
    }

    // ------------------------------------------------------------
    // Confirm Email Change
    // ------------------------------------------------------------
    if ($action === 'email_change_confirm') {
        $code = (string)($_POST['email_change_code'] ?? '');
        if ($code === '') throw new RuntimeException("Bitte Code eingeben.");

        email_change_confirm($pdo, $userId, $code, $PEPPER, true);

        unset($_SESSION['pending_new_email']);

        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // Session Email ggf. speichern? (falls du das nutzt)
        $_SESSION['email'] = (string)$user['email'];

        $ok = "E-Mail wurde erfolgreich geändert.";
    }

    // ------------------------------------------------------------
    // Resend Email Change Code
    // ------------------------------------------------------------
    if ($action === 'email_change_resend') {
        $pending = (string)($_SESSION['pending_new_email'] ?? '');
        if ($pending === '') throw new RuntimeException("Keine angefragte neue E-Mail gefunden.");

        email_change_begin(
            $pdo,
            $userId,
            (string)$user['email'],
            $pending,
            (string)$user['username'],
            $PEPPER,
            900
        );

        $ok = "Neuer Code wurde an deine alte E-Mail gesendet.";
    }

} catch (Throwable $e) {
    $err = $e->getMessage();
}

$pendingNew = (string)($_SESSION['pending_new_email'] ?? '');

?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title>Account – Settings</title>

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
        <h1 class="text-2xl font-bold text-gray-100">Account</h1>
        <p class="text-gray-400 text-sm mt-1">Profil & E-Mail verwalten</p>
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

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">

      <!-- Username ändern -->
      <section class="rounded-3xl border border-gray-800/60 bg-gray-950/50 backdrop-blur-lg p-6 shadow-xl">
        <h2 class="text-lg font-semibold text-gray-100 mb-1">Name ändern</h2>
        <p class="text-sm text-gray-400 mb-6">Dein öffentlicher Name (Username) für das Dashboard.</p>

        <form method="post" class="space-y-4" autocomplete="off">
          <input type="hidden" name="action" value="username_change">

          <div>
            <label class="block text-sm text-gray-300 mb-2">Neuer Name</label>
            <input class="w-full px-4 py-3 rounded-2xl bg-gray-900/60 border border-gray-800 text-gray-100 focus:outline-none focus:ring-2 focus:ring-violet-500/40"
                   type="text" name="new_username" value="<?= h((string)$user['username']) ?>" required>
          </div>

          <div>
            <label class="block text-sm text-gray-300 mb-2">Passwort bestätigen</label>
            <input class="w-full px-4 py-3 rounded-2xl bg-gray-900/60 border border-gray-800 text-gray-100 focus:outline-none focus:ring-2 focus:ring-violet-500/40"
                   type="password" name="password_confirm" required>
          </div>

          <button class="px-5 py-3 rounded-2xl bg-violet-600 hover:bg-violet-500 transition text-white font-semibold shadow-lg shadow-violet-600/20"
                  type="submit">
            Name speichern
          </button>

          <div class="text-xs text-gray-500">
            Erlaubt: Buchstaben, Zahlen, Leerzeichen sowie <span class="font-mono">_ . -</span> (3–32 Zeichen).
          </div>
        </form>
      </section>

      <!-- Email ändern -->
      <section class="rounded-3xl border border-gray-800/60 bg-gray-950/50 backdrop-blur-lg p-6 shadow-xl">
        <h2 class="text-lg font-semibold text-gray-100 mb-1">E-Mail ändern</h2>
        <p class="text-sm text-gray-400 mb-6">
          Zur Sicherheit wird ein Bestätigungscode an deine <b>aktuelle</b> E-Mail gesendet.
        </p>

        <form method="post" class="space-y-4" autocomplete="off">
          <input type="hidden" name="action" value="email_change_begin">

          <div>
            <label class="block text-sm text-gray-300 mb-2">Neue E-Mail</label>
            <input class="w-full px-4 py-3 rounded-2xl bg-gray-900/60 border border-gray-800 text-gray-100 focus:outline-none focus:ring-2 focus:ring-violet-500/40"
                   type="email" name="new_email" value="<?= h($pendingNew) ?>" required>
          </div>

          <div>
            <label class="block text-sm text-gray-300 mb-2">Passwort bestätigen</label>
            <input class="w-full px-4 py-3 rounded-2xl bg-gray-900/60 border border-gray-800 text-gray-100 focus:outline-none focus:ring-2 focus:ring-violet-500/40"
                   type="password" name="email_password_confirm" required>
          </div>

          <button class="px-5 py-3 rounded-2xl bg-violet-600 hover:bg-violet-500 transition text-white font-semibold shadow-lg shadow-violet-600/20"
                  type="submit">
            Code an alte E-Mail senden
          </button>
        </form>

        <div class="mt-8 pt-6 border-t border-gray-800/60">
          <h3 class="text-base font-semibold text-gray-100 mb-2">Code bestätigen</h3>
          <p class="text-sm text-gray-400 mb-4">Gib den Code ein, den du an deine alte E-Mail erhalten hast.</p>

          <form method="post" class="space-y-4" autocomplete="off">
            <input type="hidden" name="action" value="email_change_confirm">

            <div>
              <label class="block text-sm text-gray-300 mb-2">Bestätigungscode</label>
              <input class="w-full px-4 py-3 rounded-2xl bg-gray-900/60 border border-gray-800 text-gray-100 focus:outline-none focus:ring-2 focus:ring-violet-500/40"
                     type="text" name="email_change_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required>
            </div>

            <div class="flex gap-3">
              <button class="px-5 py-3 rounded-2xl bg-green-600 hover:bg-green-500 transition text-white font-semibold shadow-lg shadow-green-600/20"
                      type="submit">
                Bestätigen & ändern
              </button>

              <button class="px-5 py-3 rounded-2xl bg-gray-700 hover:bg-gray-600 transition text-white font-semibold"
                      type="submit" name="action" value="email_change_resend">
                Code neu senden
              </button>
            </div>
          </form>

          <div class="text-xs text-gray-500 mt-3">Code ist 15 Minuten gültig.</div>
        </div>
      </section>

    </div>

  </main>
</body>
</html>
