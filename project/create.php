<?php
// ============================================================================
// AUTHLY – Projekt erstellen (mit Project-Limit pro Rolle)
// PFAD: /project/create.php
// ============================================================================

require_once __DIR__ . '/../db/config.php';
require_once __DIR__ . '/../functions/dbfunctions.php';

session_start();

// Wenn nicht eingeloggt → redirect
if (!isset($_SESSION['user_id'])) {
    header("Location: /login/");
    exit();
}

$ownerId = (int)$_SESSION['user_id'];

// Helper: htmlspecialchars sicher (NULL => "")
function h($v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

// Form abgesendet?
$success = false;
$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $name         = trim((string)($_POST['name'] ?? ''));
    $version      = trim((string)($_POST['version'] ?? "1.0"));
    $hwid_enabled = isset($_POST['hwid_enabled']) ? 1 : 0;

    if ($name === "") {
        $error = "Bitte gib einen Projektnamen ein.";
    } else {

        // ---------------------------------------------------------------------
        // ✅ Project Limit Check (roles.project_limit)
        // ---------------------------------------------------------------------
        $check = canUserCreateProject($ownerId);
        if (!$check['ok']) {
            $error = "Projektlimit erreicht ({$check['current']}/{$check['limit']}). Du kannst keine weiteren Projekte erstellen.";
        } else {

            // API Keys generieren
            $api_key    = bin2hex(random_bytes(16));
            $api_secret = bin2hex(random_bytes(32));

            // Projekt erstellen (projects + project_settings(download_link))
            // download_link hier erstmal null – kannst du später in settings setzen
            $created = createProject($ownerId, $name, $api_key, $api_secret, $version, null);

            if ($created) {

                // ID des neu angelegten Projekts holen
                // (wir suchen anhand owner+api_key, weil createProject() aktuell nur bool returned)
                $stmt = $pdo->prepare("SELECT id FROM projects WHERE owner_id = :oid AND api_key = :ak LIMIT 1");
                $stmt->execute([':oid' => $ownerId, ':ak' => $api_key]);
                $newProjectId = (int)$stmt->fetchColumn();

                if ($newProjectId > 0) {
                    ensureProjectSettingsRow($newProjectId);

                    // HWID Einstellung in project_settings speichern
                    updateProjectSettings($newProjectId, $ownerId, [
                        'hwid_enabled' => $hwid_enabled
                    ]);
                }

                header("Location: /dashboard/?created=1");
                exit();

            } else {
                $error = "Datenbankfehler: Projekt konnte nicht erstellt werden.";
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <script src="https://cdn.tailwindcss.com"></script>
  <title>Neues Projekt erstellen</title>
</head>

<body class="bg-gray-900 text-gray-200 font-inter">

<div class="max-w-2xl mx-auto mt-16 bg-gray-800 p-8 rounded-2xl shadow-xl">

  <h1 class="text-2xl font-bold mb-6">Neues Projekt erstellen</h1>

  <?php if ($error): ?>
    <div class="bg-red-600/20 border border-red-500 text-red-300 rounded-lg p-3 mb-4">
      <?= h($error) ?>
    </div>
  <?php endif; ?>

  <form method="POST" class="space-y-5">

    <!-- Projektname -->
    <div>
      <label class="block text-sm text-gray-300 mb-1">Projektname</label>
      <input type="text" name="name"
             class="w-full p-3 rounded-lg bg-gray-900 border border-gray-700 text-gray-100"
             required>
    </div>

    <!-- Version -->
    <div>
      <label class="block text-sm text-gray-300 mb-1">Version</label>
      <input type="text" name="version" value="1.0"
             class="w-full p-3 rounded-lg bg-gray-900 border border-gray-700 text-gray-100">
    </div>

    <!-- HWID Toggle -->
    <div class="flex items-center gap-2">
      <input type="checkbox" name="hwid_enabled" checked class="w-4 h-4">
      <span class="text-gray-300 text-sm">HWID-Lock aktivieren</span>
    </div>

    <!-- Submit -->
    <button class="w-full bg-violet-600 hover:bg-violet-700 p-3 rounded-lg text-white font-semibold transition">
      Projekt erstellen
    </button>

  </form>

  <a href="/dashboard/" class="block mt-5 text-violet-400 hover:text-violet-300">← Zurück zum Dashboard</a>

</div>

</body>
</html>
