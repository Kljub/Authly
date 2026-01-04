<?php
// ============================================================================
// AUTHLY DASHBOARD INDEX (DB MENU ONLY)
// PFAD: /dashboard/index.php
// ============================================================================

require_once __DIR__ . '/../db/config.php';
require_once __DIR__ . '/../functions/menu.php';
require_once __DIR__ . '/../functions/icons.php';
require_once __DIR__ . '/../functions/dbfunctions.php';

session_start();

if (!isset($_SESSION['username']) || $_SESSION['username'] === "") {
    header("Location: /login/");
    exit();
}

$userRole  = $_SESSION['role_id'] ?? 1;
$userName  = $_SESSION['username'] ?? '';
$is_admin  = $_SESSION['is_admin'] ?? 0;
$ownerId   = (int)($_SESSION['user_id'] ?? 0);

// Helper: htmlspecialchars sicher (NULL => "")
function h($v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

// Projekte (alphabetisch) – MUSS status + hwid_enabled aus project_settings liefern
$projects = getProjectsByOwnerAlphabetically($ownerId);

?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
  <title>Authly Dashboard</title>

  <!-- STYLES -->
  <link href="/gui/css/style.css" rel="stylesheet">
  <link href="/gui/css/hover.css" rel="stylesheet">
  <link href="/gui/css/sidebar.css" rel="stylesheet">
  <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-900 text-gray-200 font-inter flex">

  <!-- ===============================
       SIDEBAR
  ================================ -->
  <aside class="sidebar fixed left-0 top-0 h-screen w-64 flex flex-col overflow-y-auto bg-gray-900/90 backdrop-blur-lg border border-gray-800/50 rounded-3xl m-4 shadow-xl transition-all duration-300">
    <div class="flex items-center justify-between px-6 py-4 border-b border-gray-800/50">
      <div class="flex items-center gap-2">
        <svg class="w-6 h-6 text-violet-400" viewBox="0 0 24 24"><path d="M12 2L20 20H4L12 2Z" /></svg>
        <span class="text-gray-100 font-semibold text-lg tracking-wide">Authly</span>
      </div>
    </div>

    <nav class="flex-1 px-2 mt-4">
      <?php
        // Menü kommt jetzt NUR aus der DB (routes)
        echo render_menu_dynamic($_SERVER['REQUEST_URI'], (string)$userRole);
      ?>
    </nav>

    <div class="mt-auto px-4 py-3 border-t border-gray-800/50">
      <div class="flex items-center justify-between text-gray-400 text-xs">
        <span>© <?= date('Y'); ?> Authly</span>
        <a href="/logout.php" class="hover:text-violet-400 transition">Logout</a>
      </div>
    </div>
  </aside>

  <!-- ===============================
       MAIN CONTENT
  ================================ -->
  <main class="flex-1 ml-72 p-8 transition-all">

    <!-- Header -->
    <header class="flex justify-between items-center mb-8">
      <h1 class="text-3xl font-bold text-gray-100">
        Willkommen zurück, <?= h($userName) ?> 👋
      </h1>
      <span class="text-sm text-gray-400"><?= h(ucfirst($userName)) ?></span>
    </header>

    <!-- Dashboard Cards -->
    <section class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-6">

      <!-- DEINE PROJEKTE (mit + Button) -->
      <div class="bg-gray-800 rounded-2xl p-5 shadow-md hover:shadow-lg transition">
        <div class="flex items-center justify-between">
          <div>
            <h2 class="text-gray-400 text-xs uppercase mb-1">Deine Projekte</h2>
            <p class="text-gray-100 font-semibold text-xl leading-none">
              <?= (int)getProjectCountByOwner($ownerId); ?>
            </p>
          </div>

          <a href="/project/create.php"
             class="bg-violet-600 hover:bg-violet-700 text-white w-8 h-8 flex items-center justify-center rounded-xl shadow-md transition">
             <span class="text-lg leading-none">+</span>
          </a>
        </div>
      </div>

      <!-- SYSTEMSTATUS -->
      <div class="bg-gray-800 rounded-2xl p-5 shadow-md hover:shadow-lg transition">
        <h2 class="text-gray-400 text-sm mb-1 uppercase">Systemstatus</h2>
        <p class="text-green-400 font-semibold">Läuft stabil ✅</p>
      </div>

      <!-- LETZTER LOGIN -->
      <div class="bg-gray-800 rounded-2xl p-5 shadow-md hover:shadow-lg transition">
        <h2 class="text-gray-400 text-sm mb-1 uppercase">Letzter Login (UTC)</h2>
        <p class="text-gray-100 font-semibold"><?= h(getLastLoginByUserId($ownerId)) ?></p>
      </div>

    </section>

    <!-- Projekte Tabelle -->
    <section class="mt-10">
      <div class="bg-gray-800 rounded-2xl p-6 shadow-md">
        <h3 class="text-gray-300 text-lg font-semibold mb-3">Projekte:</h3>

        <?php if (!empty($projects)): ?>

          <!-- Hidden Form zum Setzen des aktiven Projekts (Session) -->
          <form id="openProjectForm" method="POST" action="/dashboard/set_project.php">
            <input type="hidden" name="project_id" id="project_id">
          </form>

          <div class="overflow-x-auto">
            <table class="min-w-full text-sm text-gray-200">
              <thead class="text-gray-400 uppercase border-b border-gray-700/60">
                <tr>
                  <th class="px-3 py-2 text-left">Name</th>
                  <th class="px-3 py-2 text-left">API Key</th>
                  <th class="px-3 py-2 text-left">Version</th>
                  <th class="px-3 py-2 text-left">HWID</th>
                  <th class="px-3 py-2 text-left">Status</th>
                </tr>
              </thead>

              <tbody>
                <?php foreach ($projects as $proj): ?>
                  <tr
                    onclick="openProject(<?= (int)($proj['id'] ?? 0) ?>)"
                    class="border-b border-gray-700/40 hover:bg-gray-700/30 transition cursor-pointer">
                    <td class="px-3 py-2"><?= h($proj['name'] ?? '') ?></td>
                    <td class="px-3 py-2 font-mono text-gray-400"><?= h($proj['api_key'] ?? '') ?></td>
                    <td class="px-3 py-2"><?= h($proj['version'] ?? '') ?></td>
                    <td class="px-3 py-2"><?= !empty($proj['hwid_enabled']) ? '✅' : '❌' ?></td>
                    <td class="px-3 py-2">
                      <?php
                        $st = (string)($proj['status'] ?? 'active');
                        if ($st === 'active') echo "<span class='text-green-400 font-semibold'>active</span>";
                        elseif ($st === 'paused') echo "<span class='text-yellow-400 font-semibold'>paused</span>";
                        else echo "<span class='text-gray-400 font-semibold'>archived</span>";
                      ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

        <?php else: ?>
          <p class="text-gray-500 italic">Keine Projekte gefunden.</p>
        <?php endif; ?>
      </div>
    </section>

  </main>

  <!-- JS -->
  <script>
  function openProject(id) {
      const input = document.getElementById('project_id');
      const form  = document.getElementById('openProjectForm');
      if (!input || !form) return;
      input.value = id;
      form.submit();
  }
  </script>

</body>
</html>
