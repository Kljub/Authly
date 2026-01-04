<?php
// ============================================================================
// AUTHLY – ADMIN: SITE SETTINGS (Homepage wie WordPress)
// ----------------------------------------------------------------------------
// - Homepage Mode konfigurieren
// - Homepage Page auswählen (cms_pages)
// - Live Sidebar Refresh (AJAX: action=menu_html)
// ----------------------------------------------------------------------------
// PFAD: /admin/site.php
// ============================================================================

require_once __DIR__ . '/../db/config.php';
require_once __DIR__ . '/../functions/menu.php';
require_once __DIR__ . '/../functions/icons.php';

// ✅ Admin System (wie settings-routes.php)
require_once __DIR__ . '/../functions/admin_functions/admin_auth.php';
require_once __DIR__ . '/../functions/admin_functions/admin_db.php';

// ✅ Site/CMS helpers
require_once __DIR__ . '/../functions/site_settings.php';
require_once __DIR__ . '/../functions/cms_pages.php';

session_start();

// -------------------------
// Auth / Admin check
// -------------------------
$auth = require_admin();
$userRole = (string)$auth['role_id'];
$userName = (string)$auth['username'];
$ownerId  = (int)$auth['user_id'];

// -------------------------
// PDO (zentral)
// -------------------------
$pdo = admin_get_pdo();

function h($v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

/** Render sidebar menu html (for live refresh) */
function authly_render_sidebar_menu(string $userRole): string {
    if (function_exists('render_menu_dynamic')) {
        return (string)render_menu_dynamic($_SERVER['REQUEST_URI'], (string)$userRole);
    }
    return '<p class="px-4 text-gray-500 text-sm">⚠️ render_menu_dynamic() fehlt.</p>';
}

// -------------------------
// JSON endpoints (menu refresh)
// -------------------------
$ct = (string)($_SERVER['CONTENT_TYPE'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && stripos($ct, 'application/json') !== false) {
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);

    header('Content-Type: application/json; charset=utf-8');

    try {
        if (!is_array($data)) throw new RuntimeException('Invalid JSON.');
        $action = (string)($data['action'] ?? '');

        if ($action === 'menu_html') {
            echo json_encode([
                'ok' => true,
                'menu_html' => authly_render_sidebar_menu((string)$userRole)
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        throw new RuntimeException('Invalid JSON action.');

    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

// -------------------------
// Load current settings
// -------------------------
$success = '';
$error   = '';

$homepage_mode    = (string)site_get($pdo, 'homepage_mode', 'page'); // page | redirect_login | dashboard_if_logged_in
$homepage_page_id = (string)site_get($pdo, 'homepage_page_id', '');

// Pages list (for dropdown)
$pages = cms_list_pages($pdo);

// -------------------------
// Actions (POST form)
// -------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action !== 'save') {
            throw new RuntimeException('Ungültige Aktion.');
        }

        $mode = (string)($_POST['homepage_mode'] ?? 'page');
        if (!in_array($mode, ['page','redirect_login','dashboard_if_logged_in'], true)) {
            throw new RuntimeException('Ungültiger Homepage Modus.');
        }

        $pageId = (string)($_POST['homepage_page_id'] ?? '');

        if ($mode === 'page') {
            if ($pageId === '' || !ctype_digit($pageId)) {
                throw new RuntimeException('Bitte eine Homepage-Seite auswählen.');
            }
            $p = cms_get_page($pdo, (int)$pageId);
            if (!$p) throw new RuntimeException('Die ausgewählte Seite existiert nicht.');
        } else {
            // Modus ohne Seite -> leeren
            $pageId = '';
        }

        site_set($pdo, 'homepage_mode', $mode);
        site_set($pdo, 'homepage_page_id', $pageId);

        $success = '✅ Site Settings gespeichert.';

    } catch (Throwable $e) {
        $error = '❌ ' . $e->getMessage();
    }

    header("Location: site.php?ok=" . urlencode($success) . "&err=" . urlencode($error));
    exit;
}

// flash
if (isset($_GET['ok']) && $_GET['ok'] !== '') $success = (string)$_GET['ok'];
if (isset($_GET['err']) && $_GET['err'] !== '') $error = (string)$_GET['err'];

// reload settings after redirect (or initial)
$homepage_mode    = (string)site_get($pdo, 'homepage_mode', 'page');
$homepage_page_id = (string)site_get($pdo, 'homepage_page_id', '');

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Admin – Site Settings</title>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/alpinejs/3.13.5/cdn.min.js" defer></script>
    <script src="https://cdn.tailwindcss.com"></script>

    <link href="/gui/css/style.css" rel="stylesheet">
    <link href="/gui/css/sidebar.css" rel="stylesheet">
    <link href="/gui/css/project-settings.css?v=1" rel="stylesheet">

    <style>
        [x-cloak]{display:none!important;}
    </style>
</head>

<body class="bg-gray-900 text-gray-200 font-inter flex">

<!-- SIDEBAR (DB MENU) -->
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

    <nav id="authly-sidebar-menu" class="flex-1 px-2 mt-4">
        <?php echo authly_render_sidebar_menu((string)$userRole); ?>
    </nav>

    <div class="mt-auto px-4 py-3 border-t border-gray-800/50">
        <div class="flex items-center justify-between text-gray-400 text-xs">
            <span>© <?= date('Y') ?> Authly</span>
            <a href="/logout.php" class="hover:text-violet-400 transition">Logout</a>
        </div>
    </div>
</aside>

<!-- MAIN -->
<main class="flex-1 ml-72 p-10 space-y-10" x-data="{ }">

    <header class="flex items-start justify-between gap-6 flex-wrap">
        <div>
            <h1 class="text-3xl font-bold text-gray-100">Site Settings</h1>
            <p class="text-gray-400 mt-2">
                Konfiguriere, was <span class="font-mono">/index.php</span> zeigt (WordPress-Style).
            </p>
        </div>

        <div class="flex gap-3">
            <a href="/admin/pages.php"
               class="bg-gray-700 hover:bg-gray-600 text-white px-5 py-2 rounded-lg shadow text-sm">
                Pages verwalten
            </a>
            <a href="/"
               class="bg-violet-600 hover:bg-violet-700 text-white px-5 py-2 rounded-lg shadow text-sm">
                Homepage öffnen
            </a>
        </div>
    </header>

    <?php if ($success !== ''): ?>
        <div class="p-4 bg-green-800/35 border border-green-700 text-green-200 rounded-xl"><?= h($success) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="p-4 bg-red-800/35 border border-red-700 text-red-200 rounded-xl"><?= h($error) ?></div>
    <?php endif; ?>

    <!-- SETTINGS -->
    <section class="bg-gray-800 rounded-2xl p-6 shadow-xl">
        <h2 class="text-xl font-semibold text-gray-100 mb-2">Homepage</h2>
        <p class="text-sm text-gray-400 mb-6">
            Wähle einen Modus und optional eine Seite (CMS).
        </p>

        <form method="POST" class="grid grid-cols-1 md:grid-cols-6 gap-4 items-end">
            <input type="hidden" name="action" value="save">

            <div class="md:col-span-3">
                <label class="text-gray-300 text-sm">Homepage Modus</label>
                <select name="homepage_mode"
                        class="w-full p-3 rounded-lg bg-gray-900 border border-gray-700 text-gray-100 mt-1">
                    <option value="page" <?= $homepage_mode==='page'?'selected':''; ?>>
                        Statische Seite (CMS Page)
                    </option>
                    <option value="redirect_login" <?= $homepage_mode==='redirect_login'?'selected':''; ?>>
                        Immer auf Login weiterleiten
                    </option>
                    <option value="dashboard_if_logged_in" <?= $homepage_mode==='dashboard_if_logged_in'?'selected':''; ?>>
                        Wenn eingeloggt → Dashboard, sonst Seite
                    </option>
                </select>

                <p class="text-xs text-gray-500 mt-2">
                    Tipp: Bei „Dashboard if logged in“ brauchst du trotzdem eine veröffentlichte Seite als Fallback.
                </p>
            </div>

            <div class="md:col-span-3">
                <label class="text-gray-300 text-sm">Homepage Seite (nur bei Modus = page)</label>
                <select name="homepage_page_id"
                        class="w-full p-3 rounded-lg bg-gray-900 border border-gray-700 text-gray-100 mt-1">
                    <option value="">— auswählen —</option>
                    <?php foreach ($pages as $p): ?>
                        <option value="<?= (int)$p['id'] ?>"
                            <?= ((string)$p['id'] === (string)$homepage_page_id) ? 'selected' : '' ?>>
                            #<?= (int)$p['id'] ?> • <?= h($p['title']) ?> (<?= h($p['status']) ?>) • <?= h($p['slug']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="text-xs text-gray-500 mt-2">
                    Nur <span class="font-mono">published</span> Seiten sollten als Homepage genutzt werden.
                </p>
            </div>

            <div class="md:col-span-6 flex gap-3 mt-2">
                <button class="bg-violet-600 hover:bg-violet-700 text-white px-6 py-3 rounded-lg shadow">
                    Speichern
                </button>

                <button type="button" class="bg-gray-700 hover:bg-gray-600 text-white px-6 py-3 rounded-lg shadow"
                        onclick="refreshSidebarMenu()">
                    Sidebar Refresh
                </button>
            </div>
        </form>
    </section>

    <!-- INFO BOX -->
    <section class="bg-gray-800 rounded-2xl p-6 shadow-xl">
        <h2 class="text-xl font-semibold text-gray-100 mb-2">Aktueller Status</h2>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
            <div class="p-4 rounded-xl bg-gray-900/40 border border-gray-700/50">
                <div class="text-xs text-gray-400">homepage_mode</div>
                <div class="mt-1 font-mono text-gray-100"><?= h($homepage_mode) ?></div>
            </div>

            <div class="p-4 rounded-xl bg-gray-900/40 border border-gray-700/50">
                <div class="text-xs text-gray-400">homepage_page_id</div>
                <div class="mt-1 font-mono text-gray-100"><?= h($homepage_page_id === '' ? '(none)' : $homepage_page_id) ?></div>
            </div>

            <div class="p-4 rounded-xl bg-gray-900/40 border border-gray-700/50">
                <div class="text-xs text-gray-400">Hinweis</div>
                <div class="mt-1 text-sm text-gray-200">
                    Stelle sicher, dass <span class="font-mono">/index.php</span> diese Settings ausliest.
                </div>
            </div>
        </div>
    </section>

</main>

<script>
async function refreshSidebarMenu(){
    const sidebarMenuEl = document.getElementById('authly-sidebar-menu');
    try {
        const res = await fetch('site.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'menu_html' })
        });
        const j = await res.json().catch(() => null);
        if (!res.ok || !j || !j.ok) {
            alert('❌ Sidebar Refresh fehlgeschlagen.' + (j && j.error ? ("\n" + j.error) : ''));
            return;
        }
        if (sidebarMenuEl && typeof j.menu_html === 'string') {
            sidebarMenuEl.innerHTML = j.menu_html;
        }
    } catch (e) {
        alert('❌ Sidebar Refresh fehlgeschlagen (Netzwerk).');
    }
}
</script>

</body>
</html>
