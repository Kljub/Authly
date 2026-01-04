<?php
// ============================================================================
// AUTHLY – ROUTES / MENU EDITOR (DB) + Drag & Drop  (OHNE SECTIONS/GROUP_KEY)
// ----------------------------------------------------------------------------
// kind: title | group | item
// Struktur:
//   - Title
//       - Group
//           - Item
//       - Item
//
// Live Sidebar Refresh nach Drag&Drop (ohne Reload)
// Dropzones:
//   - Root akzeptiert: title
//   - Title akzeptiert: group,item
//   - Group akzeptiert: group,item
//
// PFAD: /admin/settings-routes.php
// ============================================================================

require_once __DIR__ . '/../db/config.php';
require_once __DIR__ . '/../functions/menu.php';
require_once __DIR__ . '/../functions/icons.php';

// ✅ ausgelagert
require_once __DIR__ . '/../functions/admin_functions/admin_auth.php';
require_once __DIR__ . '/../functions/admin_functions/admin_db.php';
require_once __DIR__ . '/../functions/admin_functions/routes_helpers.php';
require_once __DIR__ . '/../functions/admin_functions/routes_repo.php';
require_once __DIR__ . '/../functions/admin_functions/routes_service.php';

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
// JSON endpoints
// -------------------------
$ct = (string)($_SERVER['CONTENT_TYPE'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && stripos($ct, 'application/json') !== false) {
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);

    header('Content-Type: application/json; charset=utf-8');

    try {
        if (!is_array($data)) throw new RuntimeException('Invalid JSON.');
        $action = (string)($data['action'] ?? '');

        // -----------------------------------------------------
        // Live menu refresh only
        // -----------------------------------------------------
        if ($action === 'menu_html') {
            echo json_encode([
                'ok' => true,
                'menu_html' => authly_render_sidebar_menu((string)$userRole)
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        // -----------------------------------------------------
        // Reorder (Drag&Drop save)
        // -----------------------------------------------------
        if ($action !== 'reorder') {
            throw new RuntimeException('Invalid JSON action.');
        }

        $updates = $data['updates'] ?? null;
        if (!is_array($updates)) throw new RuntimeException('Missing updates array.');

        // ✅ Filter + Reorder komplett ausgelagert
        $filter = routes_build_filter_from_request([
            'menu_key'   => ($data['filter_menu_key'] ?? ($_GET['menu_key'] ?? 'both')),
            'scope'      => ($data['filter_scope'] ?? ($_GET['scope'] ?? 'global')),
            'project_id' => ($data['filter_project_id'] ?? ($_GET['project_id'] ?? '')),
        ]);

        routes_service_reorder($pdo, $filter, $updates);

        echo json_encode([
            'ok' => true,
            'menu_html' => authly_render_sidebar_menu((string)$userRole),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;

    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

// -------------------------
// Filter (GET) – ausgelagert
// -------------------------
$filter = routes_build_filter_from_request($_GET);
$filter_menu_key = $filter['menu_key'];
$filter_scope    = $filter['scope'];
$filter_project_id_int = $filter['project_id'];

// -------------------------
// Actions (POST form)
// -------------------------
$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'create') {
            routes_service_create($pdo, $_POST);
            $success = "✅ Route erstellt.";

        } elseif ($action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            routes_service_update($pdo, $id, $_POST);
            $success = "✅ Route aktualisiert.";

        } elseif ($action === 'toggle_active') {
            $id = (int)($_POST['id'] ?? 0);
            routes_service_toggle_active($pdo, $id);
            $success = "✅ Status geändert.";

        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            routes_service_delete($pdo, $id);
            $success = "✅ Route gelöscht.";
        }

    } catch (Throwable $e) {
        $error = "❌ " . $e->getMessage();
    }

    $qs = http_build_query([
        'menu_key'   => $filter_menu_key,
        'scope'      => $filter_scope,
        'project_id' => $filter_project_id_int ?? ''
    ]);
    header("Location: settings-routes.php?$qs&ok=" . urlencode($success) . "&err=" . urlencode($error));
    exit;
}

// flash
if (isset($_GET['ok']) && $_GET['ok'] !== '') $success = (string)$_GET['ok'];
if (isset($_GET['err']) && $_GET['err'] !== '') $error = (string)$_GET['err'];

// -------------------------
// Fetch routes (DB ausgelagert)
// -------------------------
$rows = routes_repo_fetch_all($pdo, $filter);

// Build nodes
$byId = [];
foreach ($rows as $r) {
    $r['children'] = [];
    $r['kind'] = routes_norm_kind((string)($r['kind'] ?? 'item'));
    $byId[(int)$r['id']] = $r;
}

// Link children
foreach ($byId as $id => $r) {
    $pid = $r['parent_id'] !== null ? (int)$r['parent_id'] : 0;
    if ($pid > 0 && isset($byId[$pid])) {
        $pk = routes_norm_kind((string)($byId[$pid]['kind'] ?? 'item'));
        if ($pk !== 'item') {
            $byId[$pid]['children'][] = $id;
        }
    }
}

// Parent options
$parentOptions = [];
foreach ($rows as $r) {
    $parentOptions[] = [
        'id' => (int)$r['id'],
        'label' => sprintf("#%d • %s (%s)", (int)$r['id'], (string)$r['title'], routes_norm_kind((string)$r['kind'])),
    ];
}

// Root titles
$rootTitles = [];
foreach ($byId as $id => $r) {
    $pid = $r['parent_id'] !== null ? (int)$r['parent_id'] : 0;
    if ($pid === 0 && routes_norm_kind((string)$r['kind']) === 'title') {
        $rootTitles[] = $id;
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Admin – Routes / Menü Editor</title>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/alpinejs/3.13.5/cdn.min.js" defer></script>
    <script src="https://cdn.tailwindcss.com"></script>

    <link href="/gui/css/style.css" rel="stylesheet">
    <link href="/gui/css/sidebar.css" rel="stylesheet">
    <link href="/gui/css/project-settings.css?v=1" rel="stylesheet">

    <style>
        [x-cloak]{display:none!important;}
        .drag-handle{ user-select:none; }
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
<main class="flex-1 ml-72 p-10 space-y-10" x-data="{ openCreate:false }">

    <header class="flex items-start justify-between gap-6 flex-wrap">
        <div>
            <h1 class="text-3xl font-bold text-gray-100">Routes / Menü Editor</h1>
        </div>

        <button @click="openCreate = !openCreate"
                class="bg-orange-600 hover:bg-orange-700 text-white px-5 py-2 rounded-lg shadow text-sm">
            + Neue Route
        </button>
    </header>

    <?php if ($success !== ''): ?>
        <div class="p-4 bg-green-800/35 border border-green-700 text-green-200 rounded-xl"><?= h($success) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="p-4 bg-red-800/35 border border-red-700 text-red-200 rounded-xl"><?= h($error) ?></div>
    <?php endif; ?>

    <!-- FILTERS -->
    <section class="bg-gray-800 rounded-2xl p-6 shadow-xl">
        <h2 class="text-xl font-semibold text-gray-100 mb-4">Filter</h2>

        <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div>
                <label class="text-gray-300 text-sm">menu_key</label>
                <select name="menu_key" class="w-full p-3 rounded-lg bg-gray-900 border border-gray-700 text-gray-100 mt-1">
                    <option value="dashboard"  <?= $filter_menu_key==='dashboard'?'selected':'' ?>>dashboard</option>
                    <option value="management" <?= $filter_menu_key==='management'?'selected':'' ?>>management</option>
                    <option value="both"       <?= $filter_menu_key==='both'?'selected':'' ?>>both</option>
                </select>
                <p class="text-xs text-gray-500 mt-2">
                    both = zeigt dashboard + management + both.
                </p>
            </div>

            <div>
                <label class="text-gray-300 text-sm">scope</label>
                <select name="scope" class="w-full p-3 rounded-lg bg-gray-900 border border-gray-700 text-gray-100 mt-1">
                    <option value="global"  <?= $filter_scope==='global'?'selected':'' ?>>global</option>
                    <option value="project" <?= $filter_scope==='project'?'selected':'' ?>>project</option>
                </select>
            </div>

            <div>
                <label class="text-gray-300 text-sm">project_id (nur bei scope=project)</label>
                <input name="project_id" value="<?= h($filter_project_id_int ?? '') ?>"
                       placeholder="z.B. 12"
                       class="w-full p-3 rounded-lg bg-gray-900 border border-gray-700 text-gray-100 mt-1">
            </div>

            <div class="flex items-end">
                <button class="w-full bg-violet-600 hover:bg-violet-700 text-white px-6 py-3 rounded-lg shadow">
                    Anwenden
                </button>
            </div>
        </form>
    </section>

    <!-- CREATE -->
    <section class="bg-gray-800 rounded-2xl p-6 shadow-xl" x-show="openCreate" x-cloak x-transition>
        <h2 class="text-xl font-semibold text-gray-100 mb-4">Neue Route erstellen</h2>

        <form method="POST" class="grid grid-cols-1 md:grid-cols-6 gap-4">
            <input type="hidden" name="action" value="create">

            <div>
                <label class="text-gray-300 text-sm">menu_key</label>
                <select name="menu_key" class="w-full p-3 rounded-lg bg-gray-900 border border-gray-700 text-gray-100 mt-1">
                    <option value="dashboard">dashboard</option>
                    <option value="management">management</option>
                    <option value="both" selected>both</option>
                </select>
            </div>

            <div>
                <label class="text-gray-300 text-sm">scope</label>
                <select name="scope" class="w-full p-3 rounded-lg bg-gray-900 border border-gray-700 text-gray-100 mt-1">
                    <option value="global" selected>global</option>
                    <option value="project">project</option>
                </select>
            </div>

            <div>
                <label class="text-gray-300 text-sm">project_id</label>
                <input name="project_id" placeholder="nur bei scope=project"
                       class="w-full p-3 rounded-lg bg-gray-900 border border-gray-700 text-gray-100 mt-1">
            </div>

            <div>
                <label class="text-gray-300 text-sm">kind</label>
                <select name="kind" class="w-full p-3 rounded-lg bg-gray-900 border border-gray-700 text-gray-100 mt-1">
                    <option value="title">title</option>
                    <option value="group">group</option>
                    <option value="item" selected>item</option>
                </select>
            </div>

            <div>
                <label class="text-gray-300 text-sm">parent_id (optional)</label>
                <select name="parent_id" class="w-full p-3 rounded-lg bg-gray-900 border border-gray-700 text-gray-100 mt-1">
                    <option value="">(kein parent)</option>
                    <?php foreach ($parentOptions as $po): ?>
                        <option value="<?= (int)$po['id'] ?>"><?= h($po['label']) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="text-xs text-gray-500 mt-2">
                    Title wird automatisch root (parent_id wird ignoriert).
                </p>
            </div>

            <div class="md:col-span-4">
                <label class="text-gray-300 text-sm">title</label>
                <input name="title" required
                       class="w-full p-3 rounded-lg bg-gray-900 border border-gray-700 text-gray-100 mt-1">
            </div>

            <div class="md:col-span-3">
                <label class="text-gray-300 text-sm">href (nur item)</label>
                <input name="href" placeholder="/management/user.php oder https://..."
                       class="w-full p-3 rounded-lg bg-gray-900 border border-gray-700 text-gray-100 mt-1">
            </div>

            <div class="md:col-span-3">
                <label class="text-gray-300 text-sm">icon_var</label>
                <input name="icon_var" placeholder="z.B. ico_components"
                       class="w-full p-3 rounded-lg bg-gray-900 border border-gray-700 text-gray-100 mt-1">
            </div>

            <div class="md:col-span-2">
                <label class="text-gray-300 text-sm">badge</label>
                <input name="badge" placeholder="z.B. NEW"
                       class="w-full p-3 rounded-lg bg-gray-900 border border-gray-700 text-gray-100 mt-1">
            </div>

            <div class="md:col-span-2">
                <label class="text-gray-300 text-sm">roles (CSV oder JSON)</label>
                <input name="roles_csv" placeholder="admin,user,*"
                       class="w-full p-3 rounded-lg bg-gray-900 border border-gray-700 text-gray-100 mt-1">
            </div>

            <div>
                <label class="text-gray-300 text-sm">sort_order</label>
                <input name="sort_order" type="number" value="10"
                       class="w-full p-3 rounded-lg bg-gray-900 border border-gray-700 text-gray-100 mt-1">
            </div>

            <div class="flex items-end gap-4">
                <label class="check-card w-full justify-between">
                    <input class="authly-check" type="checkbox" name="is_active" checked>
                    <span class="text-gray-300">is_active</span>
                </label>
            </div>

            <div class="flex items-end gap-4">
                <label class="check-card w-full justify-between">
                    <input class="authly-check" type="checkbox" name="editable" checked>
                    <span class="text-gray-300">editable</span>
                </label>
            </div>

            <div class="md:col-span-6 flex gap-3">
                <button class="bg-orange-600 hover:bg-orange-700 text-white px-6 py-3 rounded-lg shadow">
                    CREATE
                </button>
                <button type="button" @click="openCreate=false"
                        class="bg-gray-700 hover:bg-gray-600 text-white px-6 py-3 rounded-lg shadow">
                    Cancel
                </button>
            </div>
        </form>
    </section>

    <!-- LIST / MENU-LIKE -->
    <section class="bg-gray-800 rounded-2xl p-6 shadow-xl">
        <h2 class="text-xl font-semibold text-gray-100 mb-2">Routen (wie Menü) – Drag & Drop</h2>
        <p class="text-sm text-gray-400 mb-6">
            Root dropzone akzeptiert <span class="font-mono">title</span>.
            Title dropzone akzeptiert <span class="font-mono">group,item</span>.
            Group dropzone akzeptiert <span class="font-mono">group,item</span>.
        </p>

        <?php if ($filter_scope === 'project' && $filter_project_id_int === null): ?>
            <p class="text-yellow-300">⚠️ scope=project ausgewählt – bitte project_id setzen.</p>
        <?php elseif (empty($rows)): ?>
            <p class="text-gray-500 italic">Keine Routen gefunden.</p>
        <?php else: ?>

            <?php
            $renderNode = function($id, $depth) use (&$renderNode, $byId, $parentOptions) {
                $r = $byId[$id];

                $rolesCsv = routes_roles_json_to_csv($r['roles_json'] ?? null);
                $locked = ((int)($r['editable'] ?? 1) === 0);
                $active = ((int)($r['is_active'] ?? 1) === 1);
                $kind   = routes_norm_kind((string)$r['kind']);

                $badge   = (string)($r['badge'] ?? '');
                $iconVar = (string)($r['icon_var'] ?? '');

                $pad = $depth * 18;
                ?>
                <div class="route-card border border-gray-700/50 rounded-xl mb-3 overflow-hidden"
                     data-id="<?= (int)$r['id'] ?>"
                     data-kind="<?= h($kind) ?>"
                     data-editable="<?= $locked ? '0' : '1' ?>">

                    <div class="flex items-center gap-3 px-4 py-3 bg-gray-900/40" style="padding-left: <?= (int)($pad + 16) ?>px;">

                        <?php if (!$locked): ?>
                            <span class="drag-handle cursor-grab active:cursor-grabbing text-gray-400 hover:text-violet-300 select-none" title="Drag & Drop">⠿</span>
                        <?php else: ?>
                            <span class="text-gray-600 select-none" title="LOCKED">⠿</span>
                        <?php endif; ?>

                        <button type="button"
                                onclick="window.__authlyOpen(<?= (int)$r['id'] ?>)"
                                class="text-left flex-1 flex items-center gap-3">

                            <span class="<?= ($kind === 'title' ? 'text-sky-300' : ($kind === 'group' ? 'text-violet-300' : 'text-gray-300')) ?> text-xs font-semibold uppercase">
                                <?= h($kind) ?>
                            </span>

                            <span class="text-gray-100 font-semibold"><?= h($r['title'] ?? '') ?></span>
                            <span class="text-gray-500 text-xs">#<?= (int)$r['id'] ?></span>

                            <?php if ($badge !== ''): ?>
                                <span class="ml-2 text-[11px] px-2 py-0.5 rounded-md bg-violet-500/15 text-violet-400"><?= h($badge) ?></span>
                            <?php endif; ?>

                            <?php if ($locked): ?>
                                <span class="ml-2 text-[11px] px-2 py-0.5 rounded-md bg-red-500/15 text-red-300">LOCKED</span>
                            <?php endif; ?>

                            <?php if (!$active): ?>
                                <span class="ml-2 text-[11px] px-2 py-0.5 rounded-md bg-gray-500/15 text-gray-300">INACTIVE</span>
                            <?php endif; ?>
                        </button>

                        <form method="POST" class="inline">
                            <input type="hidden" name="action" value="toggle_active">
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <button class="text-xs px-3 py-1 rounded <?= $active ? 'bg-gray-700/60 hover:bg-gray-700' : 'bg-green-700/60 hover:bg-green-700' ?>">
                                <?= $active ? 'Disable' : 'Enable' ?>
                            </button>
                        </form>

                        <form method="POST" class="inline" onsubmit="return confirm('Route löschen?');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <button class="text-xs px-3 py-1 rounded bg-gray-700/60 hover:bg-red-600" <?= $locked ? 'disabled style="opacity:.4;cursor:not-allowed"' : '' ?>>
                                Delete
                            </button>
                        </form>
                    </div>

                    <div id="edit-<?= (int)$r['id'] ?>" class="hidden bg-gray-900/60 px-4 py-4" style="padding-left: <?= (int)($pad + 16) ?>px;">
                        <form method="POST" class="grid grid-cols-1 md:grid-cols-6 gap-3 items-end">
                            <input type="hidden" name="action" value="update">
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">

                            <div>
                                <label class="text-gray-300 text-xs">menu_key</label>
                                <select name="menu_key" class="w-full p-2 rounded-lg bg-gray-900 border border-gray-700 text-gray-100 mt-1" <?= $locked?'disabled':'' ?>>
                                    <option value="dashboard"  <?= ($r['menu_key']==='dashboard')?'selected':'' ?>>dashboard</option>
                                    <option value="management" <?= ($r['menu_key']==='management')?'selected':'' ?>>management</option>
                                    <option value="both"       <?= ($r['menu_key']==='both')?'selected':'' ?>>both</option>
                                </select>
                            </div>

                            <div>
                                <label class="text-gray-300 text-xs">scope</label>
                                <select name="scope" class="w-full p-2 rounded-lg bg-gray-900 border border-gray-700 text-gray-100 mt-1" <?= $locked?'disabled':'' ?>>
                                    <option value="global"  <?= ($r['scope']==='global')?'selected':'' ?>>global</option>
                                    <option value="project" <?= ($r['scope']==='project')?'selected':'' ?>>project</option>
                                </select>
                            </div>

                            <div>
                                <label class="text-gray-300 text-xs">project_id</label>
                                <input name="project_id" value="<?= h($r['project_id'] ?? '') ?>"
                                       class="w-full p-2 rounded-lg bg-gray-900 border border-gray-700 text-gray-100 mt-1" <?= $locked?'readonly':'' ?>>
                            </div>

                            <div>
                                <label class="text-gray-300 text-xs">kind</label>
                                <select name="kind" class="w-full p-2 rounded-lg bg-gray-900 border border-gray-700 text-gray-100 mt-1" <?= $locked?'disabled':'' ?>>
                                    <option value="title" <?= ($r['kind']==='title')?'selected':'' ?>>title</option>
                                    <option value="group" <?= ($r['kind']==='group')?'selected':'' ?>>group</option>
                                    <option value="item"  <?= ($r['kind']==='item')?'selected':'' ?>>item</option>
                                </select>
                            </div>

                            <div>
                                <label class="text-gray-300 text-xs">parent_id</label>
                                <select name="parent_id" class="w-full p-2 rounded-lg bg-gray-900 border border-gray-700 text-gray-100 mt-1" <?= $locked?'disabled':'' ?>>
                                    <option value="">(kein parent)</option>
                                    <?php foreach ($parentOptions as $po): ?>
                                        <option value="<?= (int)$po['id'] ?>" <?= ((int)($r['parent_id'] ?? 0) === (int)$po['id'])?'selected':'' ?>>
                                            <?= h($po['label']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="text-xs text-gray-500 mt-1">Title bleibt automatisch root.</p>
                            </div>

                            <div class="md:col-span-3">
                                <label class="text-gray-300 text-xs">title</label>
                                <input name="title" value="<?= h($r['title'] ?? '') ?>"
                                       class="w-full p-2 rounded-lg bg-gray-900 border border-gray-700 text-gray-100 mt-1" <?= $locked?'readonly':'' ?>>
                            </div>

                            <div class="md:col-span-3">
                                <label class="text-gray-300 text-xs">href</label>
                                <input name="href" value="<?= h($r['href'] ?? '') ?>"
                                       class="w-full p-2 rounded-lg bg-gray-900 border border-gray-700 text-gray-100 mt-1" <?= $locked?'readonly':'' ?>>
                                <p class="text-xs text-gray-500 mt-1">Für title/group wird href beim Speichern ignoriert.</p>
                            </div>

                            <div class="md:col-span-2">
                                <label class="text-gray-300 text-xs">icon_var</label>
                                <input name="icon_var" value="<?= h((string)($r['icon_var'] ?? '')) ?>"
                                       class="w-full p-2 rounded-lg bg-gray-900 border border-gray-700 text-gray-100 mt-1" <?= $locked?'readonly':'' ?>>
                            </div>

                            <div class="md:col-span-2">
                                <label class="text-gray-300 text-xs">badge</label>
                                <input name="badge" value="<?= h((string)($r['badge'] ?? '')) ?>"
                                       class="w-full p-2 rounded-lg bg-gray-900 border border-gray-700 text-gray-100 mt-1" <?= $locked?'readonly':'' ?>>
                            </div>

                            <div class="md:col-span-2">
                                <label class="text-gray-300 text-xs">roles (CSV oder JSON)</label>
                                <input name="roles_csv" value="<?= h($rolesCsv) ?>"
                                       class="w-full p-2 rounded-lg bg-gray-900 border border-gray-700 text-gray-100 mt-1" <?= $locked?'readonly':'' ?>>
                            </div>

                            <div>
                                <label class="text-gray-300 text-xs">sort_order</label>
                                <input name="sort_order" type="number" value="<?= (int)($r['sort_order'] ?? 0) ?>"
                                       class="w-full p-2 rounded-lg bg-gray-900 border border-gray-700 text-gray-100 mt-1" <?= $locked?'readonly':'' ?>>
                            </div>

                            <div class="flex items-end">
                                <label class="check-card w-full justify-between">
                                    <input class="authly-check" type="checkbox" name="is_active" <?= $active?'checked':'' ?> <?= $locked?'disabled':'' ?>>
                                    <span class="text-gray-300 text-sm">is_active</span>
                                </label>
                            </div>

                            <div class="flex items-end">
                                <label class="check-card w-full justify-between">
                                    <input class="authly-check" type="checkbox" name="editable" <?= ((int)($r['editable'] ?? 1)===1)?'checked':'' ?> <?= $locked?'disabled':'' ?>>
                                    <span class="text-gray-300 text-sm">editable</span>
                                </label>
                            </div>

                            <div class="md:col-span-6 flex gap-3">
                                <button class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg shadow text-sm" <?= $locked?'disabled style="opacity:.4;cursor:not-allowed"':'' ?>>
                                    Save
                                </button>

                                <button type="button"
                                        onclick="window.__authlyOpen(<?= (int)$r['id'] ?>)"
                                        class="bg-gray-700 hover:bg-gray-600 text-white px-6 py-2 rounded-lg shadow text-sm">
                                    Close
                                </button>
                            </div>

                            <?php if ($locked): ?>
                                <div class="md:col-span-6 text-sm text-yellow-300">
                                    ⚠️ Diese Route ist locked (editable=0). Du kannst sie nicht bearbeiten/löschen/draggen.
                                </div>
                            <?php endif; ?>
                        </form>
                    </div>

                    <?php if ($kind === 'title' || $kind === 'group'): ?>
                        <?php $accept = 'group,item'; ?>
                        <div class="route-children mt-2 px-2 pb-2"
                             data-parent-id="<?= (int)$r['id'] ?>"
                             data-accept="<?= h($accept) ?>">
                            <?php foreach (($r['children'] ?? []) as $cid) { $renderNode($cid, $depth + 1); } ?>
                        </div>
                    <?php endif; ?>

                </div>
                <?php
            };
            ?>

            <div class="border border-gray-700/40 rounded-2xl p-4 bg-gray-900/20 mb-6">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-sm font-semibold text-gray-200 uppercase tracking-wider">Menu</h3>
                    <span class="text-xs text-gray-500">Root Dropzone: title</span>
                </div>

                <div class="route-children"
                     data-parent-id=""
                     data-accept="title">
                    <?php foreach ($rootTitles as $rid) { $renderNode($rid, 0); } ?>
                </div>
            </div>

        <?php endif; ?>
    </section>

</main>

<script>
window.__authlyOpen = function(id){
    const el = document.getElementById('edit-' + id);
    if(!el) return;
    el.classList.toggle('hidden');
};
</script>

<!-- SortableJS -->
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>

<script>
(function () {

  // current filters
  const FILTER_MENU_KEY = <?= json_encode($filter_menu_key, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const FILTER_SCOPE    = <?= json_encode($filter_scope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const FILTER_PID      = <?= json_encode($filter_project_id_int ?? '', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

  const sidebarMenuEl = document.getElementById('authly-sidebar-menu');

  function closestChildrenContainer(el) {
    return el?.closest?.('.route-children') || null;
  }

  function buildUpdates(container) {
    const parentIdRaw = container.dataset.parentId ?? '';
    const parentId = parentIdRaw === '' ? null : parseInt(parentIdRaw, 10);

    const cards = Array.from(container.querySelectorAll(':scope > .route-card'));

    let order = 10;
    const updates = [];

    for (const c of cards) {
      const id = parseInt(c.dataset.id, 10);
      const editable = c.dataset.editable === '1';
      if (!editable) continue;

      updates.push({
        id,
        parent_id: parentId,
        sort_order: order
      });
      order += 10;
    }
    return updates;
  }

  async function refreshSidebarMenu(menuHtml) {
    if (!sidebarMenuEl) return;
    if (typeof menuHtml === 'string' && menuHtml.trim() !== '') {
      sidebarMenuEl.innerHTML = menuHtml;
      return;
    }

    // fallback fetch
    const res = await fetch('settings-routes.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'menu_html' })
    });
    const j = await res.json().catch(() => null);
    if (j && j.ok && typeof j.menu_html === 'string') {
      sidebarMenuEl.innerHTML = j.menu_html;
    }
  }

  async function sendReorder(allUpdates) {
    if (!allUpdates.length) return;

    const res = await fetch('settings-routes.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'reorder',
        updates: allUpdates,
        filter_menu_key: FILTER_MENU_KEY,
        filter_scope: FILTER_SCOPE,
        filter_project_id: FILTER_PID
      })
    });

    const j = await res.json().catch(() => null);
    if (!res.ok || !j || !j.ok) {
      alert('❌ Reorder speichern fehlgeschlagen.' + (j && j.error ? ("\n" + j.error) : ''));
      return;
    }

    // ✅ LIVE refresh sidebar menu
    await refreshSidebarMenu(j.menu_html || '');
  }

  function initSortable(container) {
    new Sortable(container, {
      group: 'routes',
      animation: 120,
      handle: '.drag-handle',
      draggable: '.route-card',
      ghostClass: 'opacity-40',

      onMove: function (evt) {
        const dragged = evt.dragged;
        if (!dragged) return false;

        if (dragged.dataset.editable !== '1') return false;

        const kind = dragged.dataset.kind; // title|group|item
        const toContainer = closestChildrenContainer(evt.to);
        if (!toContainer) return false;

        const accept = (toContainer.dataset.accept || '')
          .split(',')
          .map(s => s.trim())
          .filter(Boolean);

        if (accept.length && !accept.includes(kind)) return false;

        // Title darf nicht in Title/Group gedroppt werden (nur root)
        if (kind === 'title' && (toContainer.dataset.parentId ?? '') !== '') return false;

        return true;
      },

      onEnd: async function (evt) {
        const from = closestChildrenContainer(evt.from);
        const to   = closestChildrenContainer(evt.to);
        if (!from || !to) return;

        const updates = [];
        updates.push(...buildUpdates(from));
        if (to !== from) updates.push(...buildUpdates(to));

        await sendReorder(updates);
      }
    });
  }

  document.querySelectorAll('.route-children').forEach(initSortable);

})();
</script>

</body>
</html>
