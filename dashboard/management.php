<?php
// ============================================================================
// AUTHLY – PROJECT MANAGEMENT PAGE (DB MENU ONLY)
// PFAD: /dashboard/management.php
// URL: /dashboard/management.php
// Aktives Projekt kommt aus $_SESSION['active_project']
// ============================================================================

require_once __DIR__ . '/../db/config.php';
require_once __DIR__ . '/../functions/menu.php';
require_once __DIR__ . '/../functions/icons.php';
require_once __DIR__ . '/../functions/dbfunctions.php';

session_start();

// Login prüfen
if (!isset($_SESSION['user_id'])) {
    header("Location: /login/");
    exit();
}

$userRole  = $_SESSION['role_id'] ?? 1;
$userName  = $_SESSION['username'] ?? '';
$ownerId   = (int)($_SESSION['user_id'] ?? 0);

// Aktives Projekt aus der Session lesen
if (!isset($_SESSION['active_project']) || !is_numeric($_SESSION['active_project'])) {
    die("<p style='color:red;font-size:18px;text-align:center;margin-top:50px;'>❌ Kein Projekt ausgewählt.</p>");
}

$projectId = (int)$_SESSION['active_project'];

// ---------------------------------------------------------------------------
// Helper: htmlspecialchars sicher (NULL => "")
// ---------------------------------------------------------------------------
function h($v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

// Projekt laden
$project = getProjectByIdAndOwner($projectId, $ownerId);
if (!$project) {
    die("<p style='color:red;font-size:18px;text-align:center;margin-top:50px;'>❌ Projekt nicht gefunden oder Zugriff verweigert.</p>");
}

// project_settings sicherstellen + laden
ensureProjectSettingsRow($projectId);
$projectSettings = getProjectSettings($projectId, $ownerId);
if (!$projectSettings) {
    die("<p style='color:red;font-size:18px;text-align:center;margin-top:50px;'>❌ Project Settings nicht gefunden.</p>");
}

// ---------------------------------------------------------------------------
// Zusätzliche Anzeige-Werte aus project_settings
// ---------------------------------------------------------------------------
$allowRegister   = !empty($projectSettings['allow_register']);
$emailVerifyReq  = !empty($projectSettings['email_verification_required']);
$captchaEnabled  = !empty($projectSettings['captcha_enabled']);
$forceLogoutPw   = !empty($projectSettings['force_logout_on_password_change']);

$maxAttempts     = (int)($projectSettings['max_login_attempts'] ?? 5);
$cooldownSeconds = (int)($projectSettings['login_cooldown_seconds'] ?? 0);

$policyRaw = (string)($projectSettings['password_policy'] ?? '');
$policyPretty = '';
if ($policyRaw !== '') {
    $tmp = json_decode($policyRaw, true);
    if (is_array($tmp)) {
        $policyPretty = json_encode($tmp, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
  <title>Dashboard – Management</title>

  <link href="/gui/css/style.css" rel="stylesheet">
  <link href="/gui/css/sidebar.css" rel="stylesheet">
  <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-900 text-gray-200 font-inter flex">

  <!-- SIDEBAR -->
  <aside class="sidebar fixed left-0 top-0 h-screen w-64 flex flex-col overflow-y-auto
               bg-gray-900/90 backdrop-blur-lg border border-gray-800/50 rounded-3xl m-4 shadow-xl">

    <div class="flex items-center justify-between px-6 py-4 border-b border-gray-800/50">
      <div class="flex items-center gap-2">
        <svg class="w-6 h-6 text-violet-400" viewBox="0 0 24 24">
          <path d="M12 2L20 20H4L12 2Z" />
        </svg>
        <span class="text-gray-100 font-semibold text-lg tracking-wide">Authly</span>
      </div>
    </div>

    <nav class="flex-1 px-2 mt-4">
      <?php
        // Menü kommt jetzt NUR aus der DB (routes)
        // Da hier immer active_project gesetzt ist, wird automatisch 'management' + 'both' geladen.
        echo render_menu_dynamic($_SERVER['REQUEST_URI'], (string)$userRole);
      ?>
    </nav>

    <div class="mt-auto px-4 py-3 border-t border-gray-800/50">
      <div class="flex items-center justify-between text-gray-400 text-xs">
        <span>© <?= date('Y') ?> Authly</span>
        <a href="/logout.php" class="hover:text-violet-400 transition">Logout</a>
      </div>
    </div>

  </aside>

  <!-- MAIN CONTENT -->
  <main class="flex-1 ml-72 p-10">

    <!-- HEADER -->
    <header class="flex justify-between items-center mb-8">
      <h1 class="text-3xl font-bold text-gray-100">Projekt Management</h1>
      <span class="text-sm text-gray-400">
        Aktives Projekt:
        <span class="text-violet-400 font-semibold">
          <?= h($project['name'] ?? '') ?>
        </span>
      </span>
    </header>

    <!-- Management Intro Box -->
    <section class="bg-gray-800 rounded-2xl p-6 shadow-lg mb-8">
      <h2 class="text-lg font-semibold text-gray-100 mb-3">Management Übersicht</h2>
      <p class="text-gray-400 mb-4">
        Willkommen im Management Panel!
        Links findest du alle relevanten Bereiche für dieses Projekt.
      </p>

      <div class="space-y-2 text-sm">
        <a href="#" class="text-orange-400 hover:text-orange-300">Authly's Documentation</a><br>
        <a href="#" class="text-orange-400 hover:text-orange-300">Changelog</a>
      </div>
    </section>

    <!-- PROJECT INFORMATION -->
    <section class="bg-gray-800 rounded-2xl p-6 shadow-lg mb-8">
      <h3 class="text-md font-semibold text-gray-100 mb-4">Projektinformationen</h3>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
        <div>
          <p class="text-gray-400">Projekt Name</p>
          <p class="text-gray-100 font-semibold"><?= h($project['name'] ?? '') ?></p>
        </div>

        <div>
          <p class="text-gray-400">Version</p>
          <p class="text-gray-100 font-semibold"><?= h($project['version'] ?? '') ?></p>
        </div>

        <div>
          <p class="text-gray-400">API Key</p>
          <p class="text-gray-300 font-mono text-xs break-all"><?= h($project['api_key'] ?? '') ?></p>
        </div>

        <div>
          <p class="text-gray-400">API Secret</p>
          <p class="text-gray-300 font-mono text-xs break-all"><?= h($project['api_secret'] ?? '') ?></p>
        </div>

        <div>
          <p class="text-gray-400">HWID Lock</p>
          <p class="text-gray-100"><?= !empty($projectSettings['hwid_enabled']) ? '✅ aktiviert' : '❌ deaktiviert' ?></p>
        </div>

        <div>
          <p class="text-gray-400">Status</p>
          <p class="text-gray-100 font-semibold"><?= h($projectSettings['status'] ?? 'active') ?></p>
        </div>
      </div>

      <a href="/dashboard/" class="inline-block mt-6 text-violet-400 hover:text-violet-300 text-sm">
        ← Zurück zum Dashboard
      </a>
    </section>

    <!-- PROJECT SETTINGS -->
    <section class="bg-gray-800 rounded-2xl p-6 shadow-lg">
      <h3 class="text-md font-semibold text-gray-100 mb-4">Project Settings</h3>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">

        <div>
          <p class="text-gray-400">Registrierung erlaubt</p>
          <p class="text-gray-100 font-semibold"><?= $allowRegister ? '✅ Ja' : '❌ Nein' ?></p>
        </div>

        <div>
          <p class="text-gray-400">Email Verifikation erforderlich</p>
          <p class="text-gray-100 font-semibold"><?= $emailVerifyReq ? '✅ Ja' : '❌ Nein' ?></p>
        </div>

        <div>
          <p class="text-gray-400">Captcha aktiviert</p>
          <p class="text-gray-100 font-semibold"><?= $captchaEnabled ? '✅ Ja' : '❌ Nein' ?></p>
        </div>

        <div>
          <p class="text-gray-400">Force Logout bei Passwort-Änderung</p>
          <p class="text-gray-100 font-semibold"><?= $forceLogoutPw ? '✅ Ja' : '❌ Nein' ?></p>
        </div>

        <div>
          <p class="text-gray-400">Max Login Attempts</p>
          <p class="text-gray-100 font-semibold"><?= h($maxAttempts) ?></p>
        </div>

        <div>
          <p class="text-gray-400">Login Cooldown (Sekunden)</p>
          <p class="text-gray-100 font-semibold"><?= h($cooldownSeconds) ?></p>
        </div>

        <div class="md:col-span-2">
          <p class="text-gray-400">Password Policy (JSON)</p>

          <?php if ($policyPretty !== ''): ?>
            <pre class="mt-2 text-gray-300 text-xs font-mono break-words whitespace-pre-wrap bg-gray-900/40 border border-gray-700/50 rounded-xl p-4 overflow-auto"><?= h($policyPretty) ?></pre>
          <?php elseif ($policyRaw !== ''): ?>
            <pre class="mt-2 text-gray-300 text-xs font-mono break-words whitespace-pre-wrap bg-gray-900/40 border border-gray-700/50 rounded-xl p-4 overflow-auto"><?= h($policyRaw) ?></pre>
          <?php else: ?>
            <p class="text-gray-500 italic mt-1">Keine Password Policy gesetzt.</p>
          <?php endif; ?>
        </div>

      </div>
    </section>

  </main>

</body>
</html>
