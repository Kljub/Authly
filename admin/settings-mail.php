<?php
// ============================================================================
// AUTHLY – ADMIN SMTP SETTINGS + TESTMAIL
// PFAD: /admin/settings-mail.php
// ----------------------------------------------------------------------------
// DB/SMTP-Logik ausgelagert nach:
//   /functions/admin_functions/mailer.php
// ============================================================================

require_once __DIR__ . '/../db/config.php';
require_once __DIR__ . '/../functions/menu.php';
require_once __DIR__ . '/../functions/icons.php';
require_once __DIR__ . '/../functions/dbfunctions.php';

// ✅ Admin helpers (falls bei dir schon vorhanden)
require_once __DIR__ . '/../functions/admin_functions/admin_db.php';
require_once __DIR__ . '/../functions/admin_functions/admin_auth.php';

// ✅ Mailer (Settings/SMTP/Logs)
require_once __DIR__ . '/../functions/admin_functions/mailer.php';

session_start();

// -------------------------
// Auth / Admin check
// -------------------------
$auth = require_admin();

$userId   = (int)$auth['user_id'];
$userRole = (string)$auth['role_id'];
$userName = (string)$auth['username'];

function h($v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

// -------------------------
// PDO
// -------------------------
$pdo = admin_get_pdo();

// -------------------------
// Aktuelle Settings laden
// -------------------------
$cfg = [
    'smtp_enabled' => (string)settings_get($pdo, 'smtp_enabled', '0'),
    'smtp_host'    => (string)settings_get($pdo, 'smtp_host', ''),
    'smtp_port'    => (string)settings_get($pdo, 'smtp_port', '587'),
    'smtp_user'    => (string)settings_get($pdo, 'smtp_user', ''),
    'smtp_pass'    => (string)settings_get($pdo, 'smtp_pass', ''),
    'from_mail'    => (string)settings_get($pdo, 'from_mail', ''),
    'from_name'    => (string)settings_get($pdo, 'from_name', 'Noreply'),
];

$success = '';
$error   = '';
$testMsg = '';

// -------------------------
// POST Handling
// -------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Save settings
    if (isset($_POST['save_smtp'])) {
        $smtp_enabled = isset($_POST['smtp_enabled']) ? '1' : '0';

        $smtp_host = trim((string)($_POST['smtp_host'] ?? ''));
        $smtp_port = trim((string)($_POST['smtp_port'] ?? ''));
        $smtp_user = trim((string)($_POST['smtp_user'] ?? ''));
        $smtp_pass = (string)($_POST['smtp_pass'] ?? ''); // bewusst NICHT trim
        $from_mail = trim((string)($_POST['from_mail'] ?? ''));
        $from_name = trim((string)($_POST['from_name'] ?? ''));

        if ($smtp_port !== '' && !ctype_digit($smtp_port)) {
            $error = "❌ SMTP Port muss eine Zahl sein.";
        } elseif ($from_mail !== '' && !filter_var($from_mail, FILTER_VALIDATE_EMAIL)) {
            $error = "❌ Absender E-Mail ist ungültig.";
        } else {
            settings_upsert($pdo, [
                'smtp_enabled' => $smtp_enabled,
                'smtp_host'    => $smtp_host,
                'smtp_port'    => $smtp_port,
                'smtp_user'    => $smtp_user,
                'smtp_pass'    => $smtp_pass,
                'from_mail'    => $from_mail,
                'from_name'    => ($from_name !== '' ? $from_name : 'Noreply'),
            ]);

            $success = "✅ SMTP Einstellungen gespeichert.";
            log_activity($pdo, $userId, null, 'smtp_settings_update', json_encode([
                'smtp_enabled' => $smtp_enabled,
                'smtp_host'    => $smtp_host,
                'smtp_port'    => $smtp_port,
                'smtp_user'    => $smtp_user,
                'from_mail'    => $from_mail,
                'from_name'    => ($from_name !== '' ? $from_name : 'Noreply'),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            // refresh cfg
            $cfg['smtp_enabled'] = $smtp_enabled;
            $cfg['smtp_host']    = $smtp_host;
            $cfg['smtp_port']    = $smtp_port;
            $cfg['smtp_user']    = $smtp_user;
            $cfg['smtp_pass']    = $smtp_pass;
            $cfg['from_mail']    = $from_mail;
            $cfg['from_name']    = ($from_name !== '' ? $from_name : 'Noreply');
        }
    }

    // Test mail
    if (isset($_POST['send_test'])) {
        $to = trim((string)($_POST['test_to'] ?? ''));
        if ($to === '') {
            $to = get_user_email($pdo, $userId);
            if ($to === '') $to = get_user_email($pdo, 1);
        }

        if ((string)$cfg['smtp_enabled'] !== '1') {
            $testMsg = "❌ SMTP ist deaktiviert (smtp_enabled = 0).";
        } else {
            $res = smtp_test_send($cfg, $to);
            if ($res === true) {
                $testMsg = "✅ Testmail wurde erfolgreich an " . h($to) . " gesendet.";
                log_activity($pdo, $userId, null, 'smtp_test', json_encode([
                    'to'        => $to,
                    'smtp_host' => (string)$cfg['smtp_host'],
                    'smtp_port' => (string)$cfg['smtp_port'],
                    'from'      => (string)$cfg['from_mail'],
                    'ok'        => true
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } else {
                $testMsg = "❌ Testmail fehlgeschlagen: " . h((string)$res);
                log_activity($pdo, $userId, null, 'smtp_test', json_encode([
                    'to'        => $to,
                    'smtp_host' => (string)$cfg['smtp_host'],
                    'smtp_port' => (string)$cfg['smtp_port'],
                    'from'      => (string)$cfg['from_mail'],
                    'ok'        => false,
                    'error'     => (string)$res
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
        }
    }
}

// -------------------------
// Logs laden
// -------------------------
$logs = [];
try {
    $st = $pdo->prepare("
        SELECT id, user_id, action, details, ip, created_at
        FROM activity_logs
        WHERE action IN ('smtp_test','smtp_settings_update')
        ORDER BY id DESC
        LIMIT 25
    ");
    $st->execute();
    $logs = $st->fetchAll();
} catch (Throwable $e) {
    $logs = [];
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title>SMTP Settings – Admin</title>

  <script src="https://cdn.tailwindcss.com"></script>
  <link href="/gui/css/style.css" rel="stylesheet">
  <link href="/gui/css/sidebar.css" rel="stylesheet">
  <link href="/gui/css/mailer.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
</head>

<body class="bg-gray-900 text-gray-200 font-inter flex">

  <!-- Sidebar -->
  <aside class="sidebar fixed left-0 top-0 h-screen w-64 flex flex-col overflow-y-auto bg-gray-900/90 backdrop-blur-lg border border-gray-800/50 rounded-3xl m-4 shadow-xl transition-all duration-300">
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

  <!-- Main -->
  <main class="flex-1 ml-72 p-8 transition-all">

    <header class="flex justify-between items-center mb-8">
      <div>
        <h1 class="text-3xl font-bold text-gray-100">SMTP / Mail Settings</h1>
        <p class="text-gray-400 mt-1">Admin Panel – globale Mail-Konfiguration (settings Tabelle)</p>
      </div>
      <span class="text-sm text-gray-400">👤 <?= h($userName) ?></span>
    </header>

    <?php if ($success !== ''): ?>
      <div class="mb-6 bg-green-900/30 border border-green-700/40 text-green-200 px-4 py-3 rounded-2xl">
        <?= h($success) ?>
      </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
      <div class="mb-6 bg-red-900/30 border border-red-700/40 text-red-200 px-4 py-3 rounded-2xl">
        <?= h($error) ?>
      </div>
    <?php endif; ?>

    <section class="grid grid-cols-1 xl:grid-cols-2 gap-6">

      <!-- SMTP Form -->
      <div class="bg-gray-800 rounded-2xl p-6 shadow-md">
        <h2 class="text-xl font-semibold text-gray-100 mb-4">SMTP Konfiguration</h2>

        <form method="POST" class="space-y-4" autocomplete="off">
          <div class="flex items-center gap-3">
            <label class="smtp-cb">
              <input
                type="checkbox"
                name="smtp_enabled"
                id="smtp_enabled"
                <?= ((string)$cfg['smtp_enabled'] === '1') ? 'checked' : '' ?>
              >
              <div class="smtp-box">
                <svg
                  class="smtp-check"
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="3"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  width="14"
                  height="14"
                >
                  <polyline points="20 6 9 17 4 12"></polyline>
                </svg>
              </div>
              <span class="text-gray-200">SMTP aktivieren</span>
            </label>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="text-gray-400 text-sm">SMTP Host</label>
              <input name="smtp_host" value="<?= h($cfg['smtp_host']) ?>"
                     class="mt-1 w-full bg-gray-900/60 border border-gray-700 rounded-xl px-3 py-2 outline-none focus:border-violet-500">
            </div>
            <div>
              <label class="text-gray-400 text-sm">SMTP Port</label>
              <input name="smtp_port" value="<?= h($cfg['smtp_port']) ?>"
                     class="mt-1 w-full bg-gray-900/60 border border-gray-700 rounded-xl px-3 py-2 outline-none focus:border-violet-500">
              <p class="text-xs text-gray-500 mt-1">587 (STARTTLS) oder 465 (SSL)</p>
            </div>
          </div>

          <div>
            <label class="text-gray-400 text-sm">SMTP User</label>
            <input name="smtp_user" value="<?= h($cfg['smtp_user']) ?>"
                   class="mt-1 w-full bg-gray-900/60 border border-gray-700 rounded-xl px-3 py-2 outline-none focus:border-violet-500">
          </div>

          <div x-data="{ show: false }">
            <label class="text-gray-400 text-sm">SMTP Passwort</label>
            <div class="mt-1 flex gap-2">
              <input :type="show ? 'text' : 'password'" name="smtp_pass" value="<?= h($cfg['smtp_pass']) ?>"
                     class="w-full bg-gray-900/60 border border-gray-700 rounded-xl px-3 py-2 outline-none focus:border-violet-500">
              <button type="button" @click="show = !show"
                      class="bg-gray-700 hover:bg-gray-600 text-gray-100 px-3 py-2 rounded-xl transition">
                <span x-text="show ? 'Hide' : 'Show'"></span>
              </button>
            </div>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="text-gray-400 text-sm">From Mail</label>
              <input name="from_mail" value="<?= h($cfg['from_mail']) ?>"
                     class="mt-1 w-full bg-gray-900/60 border border-gray-700 rounded-xl px-3 py-2 outline-none focus:border-violet-500">
            </div>
            <div>
              <label class="text-gray-400 text-sm">From Name</label>
              <input name="from_name" value="<?= h($cfg['from_name']) ?>"
                     class="mt-1 w-full bg-gray-900/60 border border-gray-700 rounded-xl px-3 py-2 outline-none focus:border-violet-500">
            </div>
          </div>

          <div class="flex gap-3 pt-2">
            <button type="submit" name="save_smtp"
                    class="bg-violet-600 hover:bg-violet-700 text-white px-4 py-2 rounded-xl shadow-md transition">
              Speichern
            </button>
          </div>
        </form>
      </div>

      <!-- Test Mail -->
      <div class="bg-gray-800 rounded-2xl p-6 shadow-md">
        <h2 class="text-xl font-semibold text-gray-100 mb-2">Testmail senden</h2>
        <p class="text-gray-400 text-sm mb-4">
          Sendet eine Testmail (Text) über die oben gespeicherte SMTP Konfiguration.
        </p>

        <?php if ($testMsg !== ''): ?>
          <div class="mb-4 bg-gray-900/40 border border-gray-700/60 text-gray-100 px-4 py-3 rounded-2xl">
            <?= $testMsg ?>
          </div>
        <?php endif; ?>

        <form method="POST" class="space-y-4">
          <div>
            <label class="text-gray-400 text-sm">Empfänger (leer = aktueller Admin / fallback user #1)</label>
            <input name="test_to" placeholder="admin@email.tld"
                   class="mt-1 w-full bg-gray-900/60 border border-gray-700 rounded-xl px-3 py-2 outline-none focus:border-violet-500">
          </div>

          <button type="submit" name="send_test"
                  class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-xl shadow-md transition">
            Testmail senden
          </button>
        </form>

        <div class="mt-6 border-t border-gray-700/60 pt-4">
          <h3 class="text-gray-200 font-semibold mb-2">Letzte Aktionen (was „versendet/gesetzt“ wurde)</h3>

          <?php if (empty($logs)): ?>
            <p class="text-gray-500 text-sm">Keine Logs vorhanden.</p>
          <?php else: ?>
            <div class="overflow-x-auto">
              <table class="min-w-full text-sm">
                <thead class="text-gray-400 uppercase border-b border-gray-700/60">
                  <tr>
                    <th class="px-3 py-2 text-left">Zeit</th>
                    <th class="px-3 py-2 text-left">Action</th>
                    <th class="px-3 py-2 text-left">Details</th>
                    <th class="px-3 py-2 text-left">IP</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($logs as $row): ?>
                    <?php
                      $details = (string)($row['details'] ?? '');
                      $pretty = $details;
                      if ($details !== '') {
                          $tmp = json_decode($details, true);
                          if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) {
                              unset($tmp['smtp_pass']); // Passwort nie anzeigen
                              $pretty = json_encode($tmp, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                          }
                      }
                    ?>
                    <tr class="border-b border-gray-700/40">
                      <td class="px-3 py-2 text-gray-300 whitespace-nowrap"><?= h($row['created_at'] ?? '') ?></td>
                      <td class="px-3 py-2 font-mono text-violet-300 whitespace-nowrap"><?= h($row['action'] ?? '') ?></td>
                      <td class="px-3 py-2 text-gray-300">
                        <span class="font-mono text-xs break-all"><?= h($pretty) ?></span>
                      </td>
                      <td class="px-3 py-2 text-gray-400 whitespace-nowrap"><?= h($row['ip'] ?? '') ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>

      </div>
    </section>

  </main>
</body>
</html>
