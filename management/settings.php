<?php
// ============================================================================
// AUTHLY – PROJECT SETTINGS
// PFAD: /management/settings.php
// ============================================================================

require_once __DIR__ . '/../db/config.php';
require_once __DIR__ . '/../functions/menu.php';
require_once __DIR__ . '/../functions/icons.php';
require_once __DIR__ . '/../functions/dbfunctions.php';
require_once __DIR__ . '/../functions/var_manager.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: /login/");
    exit();
}

$userRole  = $_SESSION['role_id'] ?? 1;
$userName  = $_SESSION['username'] ?? '';
$ownerId   = (int)($_SESSION['user_id'] ?? 0);

if (!isset($_SESSION['active_project']) || !is_numeric($_SESSION['active_project'])) {
    die("<p style='color:red;font-size:22px;text-align:center;margin-top:50px;'>❌ Kein Projekt ausgewählt.</p>");
}

$projectId = (int)$_SESSION['active_project'];

function h($v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

$project = getProjectByIdAndOwner($projectId, $ownerId);
if (!$project) {
    die("<p style='color:red;font-size:22px;text-align:center;margin-top:50px;'>❌ Projekt nicht gefunden oder Zugriff verweigert.</p>");
}

ensureProjectSettingsRow($projectId);

$projectSettings = getProjectSettings($projectId, $ownerId);
if (!$projectSettings) {
    die("<p style='color:red;font-size:22px;text-align:center;margin-top:50px;'>❌ Project Settings nicht gefunden.</p>");
}

$successMessage = "";
$errorMessage   = "";

if (isset($_POST['delete_project'])) {
    if (deleteProjectFully($projectId, $ownerId)) {
        unset($_SESSION['active_project']);
        header("Location: /dashboard/?deleted=1");
        exit();
    } else {
        $errorMessage = "❌ Projekt konnte nicht gelöscht werden.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['delete_project'])) {

    $oldApiKey = (string)($project['api_key'] ?? '');
    $regenerateKey = isset($_POST['regenerate_api_key']) && (string)$_POST['regenerate_api_key'] === '1';
    $postedApiKey  = trim((string)($_POST['api_key'] ?? ''));

    // ----- projects -----
    $version = trim((string)($_POST['version'] ?? ''));

    // ----- project_settings (core) -----
    $download     = trim((string)($_POST['download_link'] ?? ''));
    $sessionTime  = (int)($_POST['session_expiration'] ?? 15);
    $killswitch   = isset($_POST['killswitch']) ? 1 : 0;
    $hwid_enabled = isset($_POST['hwid_enabled']) ? 1 : 0;

    if ($sessionTime > 50) $sessionTime = 50;
    if ($sessionTime < 1)  $sessionTime = 1;

    // ----- status -----
    $status = strtolower(trim((string)($_POST['status'] ?? 'active')));
    $allowedStatus = ['active','disabled','archived'];
    if (!in_array($status, $allowedStatus, true)) $status = 'active';

    // ----- NEW: Auth/UX Settings -----
    $allow_register                  = isset($_POST['allow_register']) ? 1 : 0;
    $email_verification_required     = isset($_POST['email_verification_required']) ? 1 : 0;
    $captcha_enabled                 = isset($_POST['captcha_enabled']) ? 1 : 0;
    $force_logout_on_password_change = isset($_POST['force_logout_on_password_change']) ? 1 : 0;

    $max_login_attempts = (int)($_POST['max_login_attempts'] ?? 5);
    if ($max_login_attempts < 1)  $max_login_attempts = 1;
    if ($max_login_attempts > 50) $max_login_attempts = 50;

    $login_cooldown_seconds = (int)($_POST['login_cooldown_seconds'] ?? 300);
    if ($login_cooldown_seconds < 0)      $login_cooldown_seconds = 0;
    if ($login_cooldown_seconds > 86400)  $login_cooldown_seconds = 86400;

    // password_policy JSON
    $password_policy_raw = trim((string)($_POST['password_policy'] ?? ''));
    $password_policy_db  = null;

    if ($password_policy_raw !== '') {
        $decoded = json_decode($password_policy_raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            $errorMessage = "❌ Password Policy ist kein gültiges JSON.";
        } else {
            if (!isset($decoded['min_length'])) $decoded['min_length'] = 10;
            $decoded['min_length'] = max(6, min(128, (int)$decoded['min_length']));

            $decoded['require_uppercase'] = !empty($decoded['require_uppercase']);
            $decoded['require_lowercase'] = !empty($decoded['require_lowercase']);
            $decoded['require_number']    = !empty($decoded['require_number']);
            $decoded['require_symbol']    = !empty($decoded['require_symbol']);

            $password_policy_db = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
    } else {
        $password_policy_db = null;
    }

    // ---------------------------------------------------------------------
    // Key Rotation
    // ---------------------------------------------------------------------
    $keyChanged = false;

    if ($errorMessage === "" && $regenerateKey) {
        if (!preg_match('/^[a-f0-9]{32}$/i', $postedApiKey)) {
            $errorMessage = "❌ Ungültiger Encryption Key. Erwarte 32 Hex-Zeichen.";
        } else {
            if ($postedApiKey !== $oldApiKey) {
                $keyChanged = true;

                $okRotate = updateProjectApiKeyWithVarRotation($projectId, $ownerId, $postedApiKey);
                if (!$okRotate) {
                    $err = authly_var_last_error();
                    $errorMessage = $err !== '' ? ("❌ Key-Rotation fehlgeschlagen: " . $err) : "❌ Key-Rotation fehlgeschlagen.";
                } else {
                    $project = getProjectByIdAndOwner($projectId, $ownerId);
                }
            }
        }
    }

    // ---------------------------------------------------------------------
    // Save
    // ---------------------------------------------------------------------
    if ($errorMessage === "") {

        $projectFields = [
            "version" => $version,
        ];

        $settingsFields = [
            "download_link"      => $download,
            "session_expiration" => $sessionTime,
            "killswitch_enabled" => $killswitch,
            "hwid_enabled"       => $hwid_enabled,
            "status"             => $status,

            "allow_register"                  => $allow_register,
            "email_verification_required"     => $email_verification_required,
            "password_policy"                 => $password_policy_db,
            "max_login_attempts"              => $max_login_attempts,
            "captcha_enabled"                 => $captcha_enabled,
            "login_cooldown_seconds"          => $login_cooldown_seconds,
            "force_logout_on_password_change" => $force_logout_on_password_change,
        ];

        $ok1 = updateProject($projectId, $ownerId, $projectFields);
        $ok2 = updateProjectSettings($projectId, $ownerId, $settingsFields);

        if ($ok1 && $ok2) {
            $successMessage = $keyChanged
                ? "Die Einstellungen wurden gespeichert. Encryption Key wurde neu generiert & Vars wurden kompatibel rotiert."
                : "Die Einstellungen wurden erfolgreich gespeichert.";

            $project = getProjectByIdAndOwner($projectId, $ownerId);
            $projectSettings = getProjectSettings($projectId, $ownerId);
        } else {
            $errorMessage = "❌ Fehler beim Speichern.";
        }
    }
}

// ✅ JSON-Menü raus (nur noch DB)
$policyDefault = [
  "min_length" => 10,
  "require_uppercase" => true,
  "require_lowercase" => true,
  "require_number" => true,
  "require_symbol" => false
];

$policyTextarea = '';
if (!empty($projectSettings['password_policy'])) {
    $tmp = json_decode((string)$projectSettings['password_policy'], true);
    if (is_array($tmp)) {
        $policyTextarea = json_encode($tmp, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    } else {
        $policyTextarea = (string)$projectSettings['password_policy'];
    }
} else {
    $policyTextarea = json_encode($policyDefault, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title>Project Settings – <?= h($project['name'] ?? '') ?></title>

  <script src="https://cdn.tailwindcss.com"></script>
  <link href="/gui/css/style.css" rel="stylesheet">
  <link href="/gui/css/sidebar.css" rel="stylesheet">
  <link href="/gui/css/project-settings.css?v=1" rel="stylesheet">

  <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
</head>

<body class="bg-gray-900 text-gray-200 font-inter flex">

<!-- SIDEBAR -->
<aside class="sidebar fixed left-0 top-0 h-screen w-64 flex flex-col overflow-y-auto
             bg-gray-900/90 backdrop-blur-lg border border-gray-800/50 rounded-3xl m-4 shadow-xl">

  <div class="flex items-center justify-between px-6 py-4 border-b border-gray-800/50">
    <div class="flex items-center gap-2">
      <svg class="w-6 h-6 text-violet-400" viewBox="0 0 24 24"><path d="M12 2L20 20H4L12 2Z" /></svg>
      <span class="text-gray-100 font-semibold text-lg tracking-wide">Authly</span>
    </div>
  </div>

  <nav class="flex-1 px-2 mt-4">
    <?php
      // ✅ NUR DB – dynamisch
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

<!-- MAIN -->
<main class="flex-1 ml-72 p-10">

  <h1 class="text-3xl font-bold mb-8">Project – Settings</h1>

  <?php if (!empty($successMessage)): ?>
    <div class="mb-4 p-4 bg-green-800/40 border border-green-600 text-green-300 rounded-lg">
      <?= h($successMessage) ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($errorMessage)): ?>
    <div class="mb-4 p-4 bg-red-800/40 border border-red-600 text-red-300 rounded-lg">
      <?= h($errorMessage) ?>
    </div>
  <?php endif; ?>

  <div class="bg-gray-800 rounded-2xl p-6 shadow-xl">

    <h2 class="text-xl font-semibold text-gray-100 mb-1">Update Settings :</h2>
    <p class="text-gray-400 mb-6">Here you can change your program settings</p>

    <form method="POST" class="space-y-6" id="settingsForm">

      <!-- API Key -->
      <div>
        <label class="text-gray-300 text-sm">API/Encryption Key</label>
        <div class="relative mt-1">
          <input
              id="apiKeyInput"
              name="api_key"
              value="<?= h($project['api_key'] ?? '') ?>"
              readonly
              class="w-full p-3 rounded-lg bg-gray-900 border border-gray-700 text-gray-100 readonly-lock"
              title="Der Encryption Key kann nicht frei editiert werden. Nutze den Würfel zum Regenerieren."
          >
          <input type="hidden" name="regenerate_api_key" id="regenFlag" value="0">
          <button type="button" class="icon-btn" id="regenBtn" title="Neuen Encryption Key generieren">
            <svg width="18" height="18" viewBox="0 0 24 24" class="text-violet-300" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4a2 2 0 0 0 1-1.73z"/>
              <path d="M3.27 6.96L12 12l8.73-5.04"/>
              <path d="M12 22V12"/>
            </svg>
          </button>
        </div>
        <p class="text-xs text-gray-400 mt-2">
          Hinweis: Der Key wird nur über den Würfel neu generiert. Beim Speichern werden alle Vars automatisch kompatibel rotiert.
        </p>
      </div>

      <!-- Version (projects) -->
      <div>
        <label class="text-gray-300 text-sm">Version</label>
        <input name="version" value="<?= h($project['version'] ?? '') ?>"
               class="w-full p-3 rounded-lg bg-gray-900 border border-gray-700 text-gray-100 mt-1">
      </div>

      <!-- Download Link -->
      <div>
        <label class="text-gray-300 text-sm">Download Link</label>
        <input name="download_link" value="<?= h($projectSettings['download_link'] ?? '') ?>"
               class="w-full p-3 rounded-lg bg-gray-900 border border-gray-700 text-gray-100 mt-1">
      </div>

      <!-- Session Expiration -->
      <div>
        <label class="text-gray-300 text-sm">Session Expiration Minutes</label>
        <input type="number" max="50" min="1" name="session_expiration"
               value="<?= h($projectSettings['session_expiration'] ?? 15) ?>"
               class="w-full p-3 rounded-lg bg-gray-900 border border-gray-700 text-gray-100 mt-1">
      </div>

      <!-- Project Status -->
      <div>
        <label class="text-gray-300 text-sm">Project Status</label>
        <select name="status" class="w-full p-3 rounded-lg bg-gray-900 border border-gray-700 text-gray-100 mt-1">
          <?php $st = strtolower((string)($projectSettings['status'] ?? 'active')); ?>
          <option value="active"   <?= $st==='active'?'selected':'' ?>>active (API ON)</option>
          <option value="archived" <?= $st==='archived'?'selected':'' ?>>archived (API ON)</option>
          <option value="disabled" <?= $st==='disabled'?'selected':'' ?>>disabled (API OFF)</option>
        </select>
        <p class="text-xs text-gray-400 mt-2">
          archived = API läuft weiter • disabled = API ist komplett deaktiviert.
        </p>
      </div>

      <!-- Core Toggles (nebeneinander) -->
      <div class="check-grid">
        <label class="check-card">
          <input class="authly-check" type="checkbox" name="killswitch" <?= !empty($projectSettings['killswitch_enabled']) ? 'checked' : '' ?>>
          <span class="text-gray-300">KillSwitch Enabled</span>
        </label>

        <label class="check-card">
          <input class="authly-check" type="checkbox" name="hwid_enabled" <?= !empty($projectSettings['hwid_enabled']) ? 'checked' : '' ?>>
          <span class="text-gray-300">Hardware ID Checks Enabled</span>
        </label>
      </div>

      <!-- =========================
           AUTH / REGISTER SETTINGS
      ========================== -->
      <h3 class="section-title text-lg font-semibold text-gray-100">Auth / Register</h3>

      <div class="check-grid">
        <label class="check-card">
          <input class="authly-check" type="checkbox" name="allow_register" <?= !empty($projectSettings['allow_register']) ? 'checked' : '' ?>>
          <span class="text-gray-300">Allow Register</span>
        </label>

        <label class="check-card">
          <input class="authly-check" type="checkbox" name="email_verification_required" <?= !empty($projectSettings['email_verification_required']) ? 'checked' : '' ?>>
          <span class="text-gray-300">Email verification required</span>
        </label>

        <label class="check-card">
          <input class="authly-check" type="checkbox" name="captcha_enabled" <?= !empty($projectSettings['captcha_enabled']) ? 'checked' : '' ?>>
          <span class="text-gray-300">Captcha enabled</span>
        </label>

        <label class="check-card">
          <input class="authly-check" type="checkbox" name="force_logout_on_password_change" <?= !empty($projectSettings['force_logout_on_password_change']) ? 'checked' : '' ?>>
          <span class="text-gray-300">Force logout on password change</span>
        </label>
      </div>

      <div>
        <label class="text-gray-300 text-sm">Max Login Attempts</label>
        <input type="number" min="1" max="50" name="max_login_attempts"
               value="<?= h($projectSettings['max_login_attempts'] ?? 5) ?>"
               class="w-full p-3 rounded-lg bg-gray-900 border border-gray-700 text-gray-100 mt-1">
      </div>

      <div>
        <label class="text-gray-300 text-sm">Login cooldown seconds</label>
        <input type="number" min="0" max="86400" name="login_cooldown_seconds"
               value="<?= h($projectSettings['login_cooldown_seconds'] ?? 300) ?>"
               class="w-full p-3 rounded-lg bg-gray-900 border border-gray-700 text-gray-100 mt-1">
      </div>

      <div>
        <label class="text-gray-300 text-sm">Password Policy (JSON)</label>
        <textarea
          name="password_policy"
          rows="8"
          class="w-full p-3 rounded-lg bg-gray-900 border border-gray-700 text-gray-100 mt-1 font-mono text-xs"
          spellcheck="false"
        ><?= h($policyTextarea) ?></textarea>
        <p class="text-xs text-gray-400 mt-2">
          Tipp: Leeres Feld speichert NULL (= Default im Code). JSON muss valide sein.
        </p>
      </div>

      <!-- UPDATE BUTTON -->
      <button class="bg-orange-600 hover:bg-orange-700 text-white px-6 py-3 rounded-lg shadow">
        UPDATE
      </button>

      <!-- DELETE PROJECT -->
      <hr class="my-6 border-gray-700">

      <div class="mt-6">
        <h3 class="text-lg font-semibold text-red-400 mb-2">Projekt löschen</h3>
        <p class="text-gray-400 mb-3">Achtung: Dies kann NICHT rückgängig gemacht werden.</p>

        <button type="button"
                onclick="confirmDelete()"
                class="bg-red-600 hover:bg-red-700 text-white px-6 py-3 rounded-lg shadow">
          Projekt dauerhaft löschen
        </button>
      </div>

    </form>

  </div>

</main>

<script>
function confirmDelete() {
    if (confirm("⚠️ Bist du sicher, dass du dieses Projekt löschen willst? Dies kann NICHT rückgängig gemacht werden.")) {
        let f = document.createElement("form");
        f.method = "POST";
        f.action = "";
        let i = document.createElement("input");
        i.type = "hidden";
        i.name = "delete_project";
        i.value = "1";
        f.appendChild(i);
        document.body.appendChild(f);
        f.submit();
    }
}

function bytesToHex(bytes) {
  return Array.from(bytes).map(b => b.toString(16).padStart(2, '0')).join('');
}

async function generateEncryptionKeyHex32() {
  const arr = new Uint8Array(16);
  crypto.getRandomValues(arr);
  return bytesToHex(arr);
}

document.getElementById('regenBtn')?.addEventListener('click', async () => {
  const input = document.getElementById('apiKeyInput');
  const flag  = document.getElementById('regenFlag');

  const newKey = await generateEncryptionKeyHex32();

  input.value = newKey;
  flag.value = '1';

  input.focus();
  input.select();
});
</script>

</body>
</html>
