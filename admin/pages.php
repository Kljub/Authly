<?php
// ============================================================================
// AUTHLY – ADMIN: PAGES (Mini WordPress) – FIXED (Builder Link + Quick Open)
// ----------------------------------------------------------------------------
// - CMS Pages erstellen/bearbeiten/löschen
// - Design orientiert an /admin/settings-routes.php
// - Live Sidebar Refresh (AJAX: action=menu_html)
// - ✅ FIX: Builder Button -> /admin/page-builder.php?id=XX
// ----------------------------------------------------------------------------
// PFAD: /admin/pages.php
// ============================================================================

require_once __DIR__ . '/../db/config.php';
require_once __DIR__ . '/../functions/menu.php';
require_once __DIR__ . '/../functions/icons.php';

// ✅ Admin System (wie settings-routes.php)
require_once __DIR__ . '/../functions/admin_functions/admin_auth.php';
require_once __DIR__ . '/../functions/admin_functions/admin_db.php';

// ✅ CMS helpers
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
// Actions (POST forms)
// -------------------------
$success = '';
$error   = '';

$action = (string)($_POST['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== '') {
    try {

        if ($action === 'save') {
            $id     = isset($_POST['id']) && ctype_digit((string)$_POST['id']) ? (int)$_POST['id'] : null;
            $slug   = (string)($_POST['slug'] ?? '');
            $title  = (string)($_POST['title'] ?? '');
            $status = (string)($_POST['status'] ?? 'draft');
            $content = (string)($_POST['content'] ?? '');

            $newId = cms_upsert_page($pdo, $id, $slug, $title, $content, $status);
            $success = "✅ Seite gespeichert (#{$newId}).";

            header("Location: pages.php?edit=".(int)$newId."&ok=".urlencode($success));
            exit;
        }

        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('Ungültige ID.');

            // Wenn Homepage auf diese Page zeigt -> Homepage Page resetten
            $homeId = (string)site_get($pdo, 'homepage_page_id', '');
            if ($homeId !== '' && ctype_digit($homeId) && (int)$homeId === (int)$id) {
                site_set($pdo, 'homepage_page_id', '');
            }

            cms_delete_page($pdo, $id);
            $success = "✅ Seite gelöscht (#{$id}).";

            header("Location: pages.php?ok=".urlencode($success));
            exit;
        }

        throw new RuntimeException('Ungültige Aktion.');

    } catch (Throwable $e) {
        $error = "❌ " . $e->getMessage();
        header("Location: pages.php?err=" . urlencode($error));
        exit;
    }
}

// flash
if (isset($_GET['ok']) && $_GET['ok'] !== '') $success = (string)$_GET['ok'];
if (isset($_GET['err']) && $_GET['err'] !== '') $error = (string)$_GET['err'];

// edit target
$editId = (isset($_GET['edit']) && ctype_digit((string)$_GET['edit'])) ? (int)$_GET['edit'] : 0;
$editingPage = $editId > 0 ? cms_get_page($pdo, $editId) : null;

// pages list
$pages = cms_list_pages($pdo);

// homepage state (for badge)
$homepage_mode    = (string)site_get($pdo, 'homepage_mode', 'page');
$homepage_page_id = (string)site_get($pdo, 'homepage_page_id', '');

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Admin – Pages</title>

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
<main class="flex-1 ml-72 p-10 space-y-10" x-data="{ openCreate: <?= $editingPage ? 'true' : 'false' ?> }">

    <header class="flex items-start justify-between gap-6 flex-wrap">
        <div>
            <h1 class="text-3xl font-bold text-gray-100">Pages</h1>
            <p class="text-gray-400 mt-2">
                Mini-CMS wie WordPress: <span class="font-mono">draft</span> / <span class="font-mono">published</span> + Homepage Auswahl.
            </p>
        </div>

        <div class="flex gap-3 flex-wrap">
            <a href="/admin/site.php"
               class="bg-gray-700 hover:bg-gray-600 text-white px-5 py-2 rounded-lg shadow text-sm">
                Site Settings
            </a>

            <?php if ($editId > 0): ?>
                <a href="/admin/page-builder.php?id=<?= (int)$editId ?>"
                   class="bg-violet-600 hover:bg-violet-700 text-white px-5 py-2 rounded-lg shadow text-sm">
                    Builder öffnen
                </a>
            <?php endif; ?>

            <button @click="openCreate = !openCreate"
                    class="bg-orange-600 hover:bg-orange-700 text-white px-5 py-2 rounded-lg shadow text-sm">
                <?= $editingPage ? 'Edit Panel' : '+ Neue Page' ?>
            </button>
        </div>
    </header>

    <?php if ($success !== ''): ?>
        <div class="p-4 bg-green-800/35 border border-green-700 text-green-200 rounded-xl"><?= h($success) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="p-4 bg-red-800/35 border border-red-700 text-red-200 rounded-xl"><?= h($error) ?></div>
    <?php endif; ?>

    <!-- CREATE / EDIT -->
    <section class="bg-gray-800 rounded-2xl p-6 shadow-xl" x-show="openCreate" x-cloak x-transition>
        <div class="flex items-center justify-between gap-4 flex-wrap">
            <h2 class="text-xl font-semibold text-gray-100">
                <?= $editingPage ? ("Page bearbeiten #".(int)$editingPage['id']) : "Neue Page erstellen" ?>
            </h2>

            <?php if ($editingPage): ?>
                <div class="flex gap-3 flex-wrap">
                    <a href="/admin/page-builder.php?id=<?= (int)$editingPage['id'] ?>"
                       class="bg-violet-600 hover:bg-violet-700 text-white px-4 py-2 rounded-lg shadow text-sm">
                        Builder
                    </a>
                    <a href="/admin/pages.php"
                       class="bg-gray-700 hover:bg-gray-600 text-white px-4 py-2 rounded-lg shadow text-sm">
                        Neue Page
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <form method="POST" class="grid grid-cols-1 md:grid-cols-6 gap-4 mt-5">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int)($editingPage['id'] ?? 0) ?>">

            <div class="md:col-span-3">
                <label class="text-gray-300 text-sm">slug</label>
                <input name="slug"
                       value="<?= h((string)($editingPage['slug'] ?? '')) ?>"
                       placeholder="z.B. home, impressum, about"
                       class="w-full p-3 rounded-lg bg-gray-900 border border-gray-700 text-gray-100 mt-1">
                <p class="text-xs text-gray-500 mt-2">
                    Wird automatisch „slugified“ (leer → auto).
                </p>
            </div>

            <div class="md:col-span-3">
                <label class="text-gray-300 text-sm">status</label>
                <select name="status" class="w-full p-3 rounded-lg bg-gray-900 border border-gray-700 text-gray-100 mt-1">
                    <option value="draft" <?= (($editingPage['status'] ?? '')==='draft')?'selected':''; ?>>draft</option>
                    <option value="published" <?= (($editingPage['status'] ?? '')==='published')?'selected':''; ?>>published</option>
                </select>
                <p class="text-xs text-gray-500 mt-2">
                    Nur <span class="font-mono">published</span> kann als Homepage genutzt werden.
                </p>
            </div>

            <div class="md:col-span-6">
                <label class="text-gray-300 text-sm">title</label>
                <input name="title" required
                       value="<?= h((string)($editingPage['title'] ?? '')) ?>"
                       class="w-full p-3 rounded-lg bg-gray-900 border border-gray-700 text-gray-100 mt-1">
            </div>

            <div class="md:col-span-6">
                <label class="text-gray-300 text-sm">content (HTML)</label>
                <textarea name="content" rows="14"
                          class="w-full p-3 rounded-lg bg-gray-900 border border-gray-700 text-gray-100 mt-1 font-mono text-sm"><?= h((string)($editingPage['content'] ?? '')) ?></textarea>
                <p class="text-xs text-gray-500 mt-2">
                    Content wird beim Speichern serverseitig gesäubert (keine onClick/onLoad, kein javascript: in links).
                </p>
            </div>

            <div class="md:col-span-6 flex gap-3 flex-wrap">
                <button class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg shadow">
                    Save
                </button>

                <button type="button" @click="openCreate=false"
                        class="bg-gray-700 hover:bg-gray-600 text-white px-6 py-3 rounded-lg shadow">
                    Close
                </button>

                <button type="button" class="bg-gray-700 hover:bg-gray-600 text-white px-6 py-3 rounded-lg shadow"
                        onclick="refreshSidebarMenu()">
                    Sidebar Refresh
                </button>

                <?php if ($editingPage): ?>
                    <a href="/admin/page-builder.php?id=<?= (int)$editingPage['id'] ?>"
                       class="bg-violet-600 hover:bg-violet-700 text-white px-6 py-3 rounded-lg shadow">
                        Builder
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <!-- LIST -->
    <section class="bg-gray-800 rounded-2xl p-6 shadow-xl">
        <div class="flex items-center justify-between flex-wrap gap-4 mb-4">
            <div>
                <h2 class="text-xl font-semibold text-gray-100">Alle Pages</h2>
                <p class="text-sm text-gray-400 mt-1">
                    Homepage: <span class="font-mono"><?= h($homepage_mode) ?></span>
                    <?php if ($homepage_page_id !== ''): ?>
                        · Page ID: <span class="font-mono"><?= h($homepage_page_id) ?></span>
                    <?php endif; ?>
                </p>
            </div>

            <div class="flex gap-3 flex-wrap">
                <a href="/admin/site.php"
                   class="bg-violet-600 hover:bg-violet-700 text-white px-5 py-2 rounded-lg shadow text-sm">
                    Homepage konfigurieren
                </a>
                <a href="/"
                   class="bg-gray-700 hover:bg-gray-600 text-white px-5 py-2 rounded-lg shadow text-sm">
                    Homepage öffnen
                </a>
            </div>
        </div>

        <?php if (empty($pages)): ?>
            <p class="text-gray-500 italic">Keine Pages vorhanden.</p>
        <?php else: ?>

            <div class="space-y-3">
                <?php foreach ($pages as $p): ?>
                    <?php
                        $isHome = ($homepage_mode === 'page'
                                  && $homepage_page_id !== ''
                                  && ctype_digit($homepage_page_id)
                                  && (int)$homepage_page_id === (int)$p['id']);
                        $status = (string)($p['status'] ?? 'draft');
                        $slug = (string)($p['slug'] ?? '');
                    ?>
                    <div class="border border-gray-700/50 rounded-xl overflow-hidden">
                        <div class="flex items-center gap-3 px-4 py-3 bg-gray-900/40">
                            <div class="flex-1">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="text-gray-100 font-semibold"><?= h($p['title']) ?></span>
                                    <span class="text-gray-500 text-xs">#<?= (int)$p['id'] ?></span>
                                    <span class="text-[11px] px-2 py-0.5 rounded-md <?= $status==='published' ? 'bg-green-500/15 text-green-300' : 'bg-gray-500/15 text-gray-300' ?>">
                                        <?= h($status) ?>
                                    </span>
                                    <?php if ($isHome): ?>
                                        <span class="text-[11px] px-2 py-0.5 rounded-md bg-violet-500/15 text-violet-300">
                                            HOMEPAGE
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="text-xs text-gray-500 mt-1 font-mono">
                                    slug: <?= h($slug) ?>
                                </div>
                            </div>

                            <!-- ✅ FIX: Builder Link -->
                            <a class="text-xs px-3 py-1 rounded bg-violet-600/70 hover:bg-violet-600 text-white"
                               href="/admin/page-builder.php?id=<?= (int)$p['id'] ?>">
                                Builder
                            </a>

                            <a class="text-xs px-3 py-1 rounded bg-blue-600/70 hover:bg-blue-600 text-white"
                               href="/admin/pages.php?edit=<?= (int)$p['id'] ?>">
                                Edit
                            </a>

                            <form method="POST" class="inline" onsubmit="return confirm('Page wirklich löschen?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                <button class="text-xs px-3 py-1 rounded bg-gray-700/60 hover:bg-red-600 text-white">
                                    Delete
                                </button>
                            </form>
                        </div>

                        <div class="px-4 py-3 bg-gray-900/20">
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-3 text-xs text-gray-400">
                                <div>
                                    <span class="text-gray-500">created:</span>
                                    <span class="text-gray-300"><?= h((string)($p['created_at'] ?? '')) ?></span>
                                </div>
                                <div>
                                    <span class="text-gray-500">updated:</span>
                                    <span class="text-gray-300"><?= h((string)($p['updated_at'] ?? '')) ?></span>
                                </div>
                                <div>
                                    <span class="text-gray-500">preview:</span>
                                    <span class="text-gray-300">
                                      <a class="hover:text-violet-400" href="/?page=<?= urlencode($slug) ?>" target="_blank">/?page=<?= h($slug) ?></a>
                                    </span>
                                </div>
                            </div>
                        </div>

                    </div>
                <?php endforeach; ?>
            </div>

        <?php endif; ?>
    </section>

</main>

<script>
async function refreshSidebarMenu(){
    const sidebarMenuEl = document.getElementById('authly-sidebar-menu');
    try {
        const res = await fetch('pages.php', {
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
