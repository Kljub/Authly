<?php
// ============================================================================
// AUTHLY – PROJECT VARS MANAGEMENT (UI)
// PFAD: /management/vars.php
// ============================================================================

require_once __DIR__ . '/../db/config.php';
require_once __DIR__ . '/../functions/menu.php';
require_once __DIR__ . '/../functions/icons.php';
require_once __DIR__ . '/../functions/dbfunctions.php';
require_once __DIR__ . '/../functions/var_manager.php';

session_start();

// Login prüfen
if (!isset($_SESSION['user_id'])) {
    header("Location: /login/");
    exit();
}

// Aktives Projekt prüfen
if (!isset($_SESSION['active_project']) || !is_numeric($_SESSION['active_project'])) {
    die("<p style='color:red;font-size:22px;text-align:center;margin-top:50px;'>❌ Kein Projekt ausgewählt.</p>");
}

$projectId = (int)$_SESSION['active_project'];
$ownerId   = (int)$_SESSION['user_id'];
$userRole  = (string)($_SESSION['role_id'] ?? '');
$userName  = (string)($_SESSION['username'] ?? '');

// Projekt prüfen (Owner-Zugriff)
$project = getProjectByIdAndOwner($projectId, $ownerId);
if (!$project) {
    die("<p style='color:red;font-size:22px;text-align:center;margin-top:50px;'>❌ Zugriff verweigert.</p>");
}

// Zusätzliche Flag (explizit)
$isOwner = ((int)($project['owner_id'] ?? 0) === (int)$ownerId);

// -----------------------------------------------------------------------------
// ACTIONS (POST)
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Create var
    if (isset($_POST['create_var'])) {
        $name  = (string)($_POST['var_name'] ?? '');
        $value = (string)($_POST['var_value'] ?? '');

        $ok = createProjectVar($projectId, $name, $value);
        if ($ok) {
            header("Location: vars.php?created=1");
            exit;
        }
        $error = authly_var_last_error();
        if ($error === '') $error = "Var konnte nicht erstellt werden.";
    }

    // Update var
    if (isset($_POST['update_var_id'])) {
        $varId = (int)($_POST['update_var_id'] ?? 0);
        $value = (string)($_POST['new_var_value'] ?? '');

        $ok = updateProjectVar($projectId, $varId, $value);
        if ($ok) {
            header("Location: vars.php?updated=1");
            exit;
        }
        $error = authly_var_last_error();
        if ($error === '') $error = "Var konnte nicht aktualisiert werden.";
    }

    // Delete var
    if (isset($_POST['delete_var_id'])) {
        $varId = (int)($_POST['delete_var_id'] ?? 0);
        if ($varId > 0) {
            deleteProjectVar($projectId, $varId);
        }
        header("Location: vars.php?deleted=1");
        exit;
    }
}

// Vars laden
$vars = getProjectVars($projectId);

// Owner-only: decrypted values vorbereiten
if ($isOwner) {
    foreach ($vars as &$v) {
        $v['value_plain'] = decryptVarForProject($projectId, (string)($v['value_enc'] ?? ''));
    }
    unset($v);
} else {
    foreach ($vars as &$v) {
        $v['value_plain'] = '';
    }
    unset($v);
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Project Vars – <?= htmlspecialchars($project['name'] ?? '') ?></title>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/alpinejs/3.13.5/cdn.min.js" defer></script>
    <script src="https://cdn.tailwindcss.com"></script>

    <link href="/gui/css/style.css" rel="stylesheet">
    <link href="/gui/css/sidebar.css" rel="stylesheet">

    <style>[x-cloak]{display:none!important;}</style>
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
            // ✅ NUR DB – keine JSON mehr
            echo render_menu_dynamic($_SERVER['REQUEST_URI'], $userRole);
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
<main class="flex-1 ml-72 p-10 space-y-10">

    <!-- VARS TABLE -->
    <section class="bg-gray-800 rounded-2xl p-6 shadow-xl"
             x-data="{
                q: '',
                norm(s){ return (s ?? '').toString().toLowerCase().trim(); },
                match(name, plain, enc){
                    const query = this.norm(this.q);
                    if(!query) return true;

                    // Non-owner: nur Name durchsuchen (keine Values vorhanden)
                    <?php if (!$isOwner): ?>
                    return this.norm(name).includes(query);
                    <?php else: ?>
                    return (
                        this.norm(name).includes(query) ||
                        this.norm(plain).includes(query) ||
                        this.norm(enc).includes(query)
                    );
                    <?php endif; ?>
                }
             }">

        <div class="flex items-start justify-between gap-6 flex-wrap">
            <div>
                <h1 class="text-2xl font-bold text-gray-100 mb-1">Vars Management :</h1>
                <p class="text-gray-400">Project Vars (verschlüsselt gespeichert).</p>
            </div>

            <!-- SEARCH -->
            <div class="w-full sm:w-auto flex items-center gap-2">
                <div class="relative w-full sm:w-[420px]">
                    <input
                        x-model="q"
                        type="text"
                        placeholder="Suchen (Name<?= $isOwner ? ', decrypted, encrypted' : '' ?>)…"
                        class="w-full bg-gray-900 border border-gray-700 rounded-xl px-4 py-2 pr-10 text-gray-100 placeholder:text-gray-500 focus:outline-none focus:ring-2 focus:ring-violet-500/40"
                    >
                    <button
                        type="button"
                        x-show="q.length"
                        x-cloak
                        @click="q=''"
                        class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-200 text-xs px-2 py-1 rounded"
                        title="Reset"
                    >
                        ✕
                    </button>
                </div>
            </div>
        </div>

        <?php if (!empty($error)): ?>
            <p class="text-red-400 text-sm mt-4"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>

        <?php if (isset($_GET['created'])): ?>
            <p class="text-green-400 text-sm mt-4">✅ Var erstellt.</p>
        <?php endif; ?>
        <?php if (isset($_GET['updated'])): ?>
            <p class="text-green-400 text-sm mt-4">✅ Var aktualisiert.</p>
        <?php endif; ?>
        <?php if (isset($_GET['deleted'])): ?>
            <p class="text-green-400 text-sm mt-4">✅ Var gelöscht.</p>
        <?php endif; ?>

        <?php if (!$isOwner): ?>
            <p class="text-yellow-300 text-sm mt-4">⚠️ Hinweis: Values werden nur für den Projekt-Owner angezeigt.</p>
        <?php endif; ?>

        <div class="mt-6 overflow-x-auto">
            <table class="w-full table-fixed text-sm text-gray-200">
                <colgroup>
                    <col style="width:240px">
                    <?php if ($isOwner): ?>
                        <col style="width:360px">
                        <col style="width:auto">
                    <?php endif; ?>
                    <col style="width:220px">
                </colgroup>

                <thead class="text-gray-400 uppercase border-b border-gray-700/60">
                <tr>
                    <th class="px-6 py-2 text-left">Name</th>
                    <?php if ($isOwner): ?>
                        <th class="px-6 py-2 text-left">Value (Decrypted)</th>
                        <th class="px-6 py-2 text-left">Value (Encrypted)</th>
                    <?php endif; ?>
                    <th class="px-6 py-2 text-right">Actions</th>
                </tr>
                </thead>

                <tbody class="divide-y divide-gray-800">
                <?php if (empty($vars)): ?>
                    <tr>
                        <td colspan="<?= $isOwner ? 4 : 2 ?>" class="px-6 py-6 text-gray-500 italic">Keine Vars gefunden.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($vars as $v): ?>
                        <?php
                        $plain = (string)($v['value_plain'] ?? '');
                        $enc   = (string)($v['value_enc'] ?? '');

                        $plainPreview = (strlen($plain) > 60) ? (substr($plain, 0, 60) . '…') : $plain;
                        $encPreview   = (strlen($enc) > 90) ? (substr($enc, 0, 90) . '…') : $enc;
                        $rowId = (int)($v['id'] ?? 0);
                        ?>

                        <tbody x-data="{ open:false }">
                            <tr x-show="match('<?= htmlspecialchars((string)($v['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>',
                                              '<?= htmlspecialchars((string)$plain, ENT_QUOTES, 'UTF-8') ?>',
                                              '<?= htmlspecialchars((string)$enc, ENT_QUOTES, 'UTF-8') ?>')"
                                class="hover:bg-gray-700/20 transition">

                                <td class="px-6 py-3 font-semibold"><?= htmlspecialchars($v['name'] ?? '') ?></td>

                                <?php if ($isOwner): ?>
                                    <td class="px-6 py-3 font-mono text-xs text-green-300 truncate"
                                        title="<?= htmlspecialchars($plain) ?>">
                                        <?= htmlspecialchars($plainPreview) ?>
                                    </td>

                                    <td class="px-6 py-3 font-mono text-xs text-gray-400 truncate"
                                        title="<?= htmlspecialchars($enc) ?>">
                                        <?= htmlspecialchars($encPreview) ?>
                                    </td>
                                <?php endif; ?>

                                <td class="px-6 py-3 text-right whitespace-nowrap space-x-2">
                                    <button @click="open=!open"
                                            class="text-xs px-3 py-1 rounded bg-gray-700/60 hover:bg-gray-700">
                                        Edit
                                    </button>

                                    <form method="POST" class="inline" onsubmit="return confirm('Var wirklich löschen?');">
                                        <input type="hidden" name="delete_var_id" value="<?= $rowId ?>">
                                        <button class="text-xs px-3 py-1 rounded bg-gray-700/60 hover:bg-red-600">
                                            Delete
                                        </button>
                                    </form>
                                </td>
                            </tr>

                            <tr x-show="open" x-cloak class="bg-gray-900 border-b border-gray-800">
                                <td colspan="<?= $isOwner ? 4 : 2 ?>" class="px-6 py-4" @click.stop>
                                    <?php if (!$isOwner): ?>
                                        <p class="text-yellow-300 text-sm">Nur der Projekt-Owner kann Values bearbeiten/sehen.</p>
                                    <?php else: ?>
                                        <form method="POST" class="grid grid-cols-1 md:grid-cols-6 gap-3 items-end">
                                            <input type="hidden" name="update_var_id" value="<?= $rowId ?>">

                                            <div class="md:col-span-5">
                                                <label class="text-gray-300 text-sm">New Value (Decrypted)</label>
                                                <textarea name="new_var_value"
                                                          class="w-full bg-gray-800 border border-gray-700 rounded px-3 py-2 text-gray-100 h-24"
                                                          required><?= htmlspecialchars($plain) ?></textarea>

                                                <p class="text-xs text-gray-500 mt-2">
                                                    Encrypted (read-only):
                                                    <span class="font-mono break-all"><?= htmlspecialchars($enc) ?></span>
                                                </p>
                                            </div>

                                            <div class="md:col-span-1">
                                                <button type="submit"
                                                        class="w-full bg-blue-600 hover:bg-blue-700 text-white py-2 rounded text-xs">
                                                    Save
                                                </button>
                                            </div>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </tbody>

                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

    </section>

    <!-- CREATE VAR -->
    <section class="bg-gray-800 rounded-2xl p-6 shadow-xl">
        <h2 class="text-xl font-semibold text-gray-100 mb-4">Var erstellen</h2>

        <?php if (!$isOwner): ?>
            <p class="text-yellow-300 text-sm">Nur der Projekt-Owner kann Vars erstellen.</p>
        <?php else: ?>
            <form method="POST" class="grid grid-cols-1 md:grid-cols-6 gap-4">
                <div class="md:col-span-2">
                    <label class="text-gray-300 text-sm">Name</label>
                    <input name="var_name"
                           class="bg-gray-900 border border-gray-700 rounded px-3 py-2 w-full text-gray-100"
                           required>
                </div>

                <div class="md:col-span-4">
                    <label class="text-gray-300 text-sm">Value (Decrypted)</label>
                    <input name="var_value"
                           class="bg-gray-900 border border-gray-700 rounded px-3 py-2 w-full text-gray-100"
                           required>
                </div>

                <div class="md:col-span-6">
                    <button type="submit" name="create_var"
                            class="bg-orange-600 hover:bg-orange-700 text-white px-6 py-2 rounded shadow text-sm">
                        CREATE VAR
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </section>

</main>

</body>
</html>
