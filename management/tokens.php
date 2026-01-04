<?php
// ============================================================================
// AUTHLY – PROJECT TOKEN MANAGEMENT (UI) – inkl. Filter (all/used/unused)
// PFAD: /management/tokens.php
// FEATURES:
// - Filter: all / used / unused
// - Create Tokens (presets + custom mask with { ... } randomization)
// - ID wird NICHT angezeigt (nur intern genutzt)
// - Single delete
// - Toggle Used/Unused Button neben Delete
// - Multi-Select Delete (Checkbox + Delete Selected + Select All)
// ============================================================================

require_once __DIR__ . '/../db/config.php';
require_once __DIR__ . '/../functions/menu.php';
require_once __DIR__ . '/../functions/icons.php';
require_once __DIR__ . '/../functions/dbfunctions.php';
require_once __DIR__ . '/../functions/token_manager.php';

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

// Projekt prüfen
$project = getProjectByIdAndOwner($projectId, $ownerId);
if (!$project) {
    die("<p style='color:red;font-size:22px;text-align:center;margin-top:50px;'>❌ Zugriff verweigert.</p>");
}

// -----------------------------------------------------------------------------
// FILTER (GET): all | used | unused
// -----------------------------------------------------------------------------
$filter = (string)($_GET['filter'] ?? 'all');
$filter = strtolower(trim($filter));
if (!in_array($filter, ['all', 'used', 'unused'], true)) {
    $filter = 'all';
}

// -----------------------------------------------------------------------------
// ACTIONS (POST)
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Create tokens
    if (isset($_POST['create_tokens'])) {
        $amount = (int)($_POST['token_amount'] ?? 0);
        $days   = (int)($_POST['token_days'] ?? 0);
        $rankId = (int)($_POST['token_rank'] ?? 0);

        $type       = (string)($_POST['token_type'] ?? 'normal');
        $customMask = (string)($_POST['token_mask'] ?? '');

        $mask = authlyTokenMaskFromType($type, $customMask);

        if ($amount <= 0) {
            $error = "Bitte eine gültige Anzahl an Tokens angeben.";
        } elseif ($amount > 500) {
            $error = "Maximal 500 Tokens pro Vorgang.";
        } elseif (trim($mask) === '') {
            $error = "Mask darf nicht leer sein.";
        } else {
            $created = createProjectTokens($projectId, $amount, $days, $rankId, $mask);

            if ($created > 0) {
                header("Location: tokens.php?filter=" . urlencode($filter) . "&created=" . (int)$created);
                exit;
            }

            $error = authly_token_last_error();
            if ($error === '') {
                $error = "Es konnten keine Tokens erstellt werden.";
            }
        }
    }

    // Toggle used/unused
    if (isset($_POST['toggle_used_token_id'])) {
        $tid = (int)$_POST['toggle_used_token_id'];
        if ($tid > 0 && function_exists('toggleProjectTokenUsed')) {
            toggleProjectTokenUsed($projectId, $tid);
        }
        header("Location: tokens.php?filter=" . urlencode($filter));
        exit;
    }

    // Delete one token
    if (isset($_POST['delete_token_id'])) {
        $tid = (int)$_POST['delete_token_id'];
        if ($tid > 0) {
            deleteProjectToken($projectId, $tid);
        }
        header("Location: tokens.php?filter=" . urlencode($filter) . "&deleted=1");
        exit;
    }

    // Bulk delete selected
    if (isset($_POST['bulk_delete_tokens'])) {
        $selected = $_POST['selected_token_ids'] ?? [];
        if (!is_array($selected)) $selected = [];

        $deletedCount = function_exists('deleteProjectTokensBulk')
            ? deleteProjectTokensBulk($projectId, $selected)
            : 0;

        header("Location: tokens.php?filter=" . urlencode($filter) . "&deleted_count=" . (int)$deletedCount);
        exit;
    }

    // Purge all tokens
    if (isset($_POST['purge_all_tokens'])) {
        purgeAllProjectTokens($projectId);
        header("Location: tokens.php?filter=" . urlencode($filter) . "&purged=all");
        exit;
    }

    // Purge used tokens
    if (isset($_POST['purge_used_tokens'])) {
        purgeUsedProjectTokens($projectId);
        header("Location: tokens.php?filter=" . urlencode($filter) . "&purged=used");
        exit;
    }

    // Purge unused tokens
    if (isset($_POST['purge_unused_tokens'])) {
        purgeUnusedProjectTokens($projectId);
        header("Location: tokens.php?filter=" . urlencode($filter) . "&purged=unused");
        exit;
    }
}

// -----------------------------------------------------------------------------
// TOKENS LADEN (Filter aktiv)
// -----------------------------------------------------------------------------
$tokens = getProjectTokens($projectId, $filter);

// Presets
$presets = authlyTokenPresets();

// Defaults (Persistenz im Form)
$formType = (string)($_POST['token_type'] ?? 'normal');
$formMask = (string)($_POST['token_mask'] ?? $presets['custom']);

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Project Tokens – <?= htmlspecialchars($project['name'] ?? '') ?></title>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/alpinejs/3.13.5/cdn.min.js" defer></script>
    <script src="https://cdn.tailwindcss.com"></script>

    <link href="/gui/css/style.css" rel="stylesheet">
    <link href="/gui/css/sidebar.css" rel="stylesheet">
    <link href="/gui/css/project-settings.css" rel="stylesheet">

    <style>
        [x-cloak]{display:none!important;}

        /*
          WICHTIG:
          ::after auf <input> funktioniert in vielen Browsern NICHT zuverlässig.
          Daher: Check-Mark als background-image (SVG Data URI) -> funktioniert stabil.
        */

        /* ✅ AUTHLY TOKEN CHECKBOX (dark + violet + white checkmark) */
        .authly-token-check{
            appearance: none;
            -webkit-appearance: none;
            width: 18px;
            height: 18px;
            border: 2px solid rgba(107,114,128,.9);
            border-radius: 6px;
            background: rgba(17,24,39,1);
            cursor: pointer;
            transition: all .12s ease;
            display: inline-block;
            vertical-align: middle;
            background-repeat: no-repeat;
            background-position: center;
            background-size: 12px 12px;
        }

        .authly-token-check:hover{
            border-color: rgba(139,92,246,.9);
        }

        .authly-token-check:focus{
            outline: none;
            box-shadow: 0 0 0 3px rgba(139,92,246,.25);
        }

        .authly-token-check:checked{
            background-color: rgba(139,92,246,1);
            border-color: rgba(139,92,246,1);

            /* ✅ White checkmark SVG */
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath d='M20 6L9 17l-5-5' fill='none' stroke='%23ffffff' stroke-width='3' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
        }

        /* Indeterminate (Select All) */
        .authly-token-check:indeterminate{
            background-color: rgba(139,92,246,0.35);
            border-color: rgba(139,92,246,1);
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath d='M6 12h12' fill='none' stroke='%23ffffff' stroke-width='3' stroke-linecap='round'/%3E%3C/svg%3E");
        }
    </style>
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
        <?= render_menu_dynamic($_SERVER['REQUEST_URI'], $userRole); ?>
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

    <!-- TOKENS TABLE -->
    <section class="bg-gray-800 rounded-2xl p-6 shadow-xl">
        <div class="flex items-start justify-between gap-6 flex-wrap">
            <div>
                <h1 class="text-2xl font-bold text-gray-100 mb-1">Token Management :</h1>
                <p class="text-gray-400">Tokens für dein aktuelles Projekt verwalten.</p>
            </div>

            <div class="flex items-center gap-2">
                <a href="tokens.php?filter=all" class="px-3 py-1 rounded text-xs <?= $filter==='all'?'bg-violet-600':'bg-gray-700/60 hover:bg-gray-700' ?>">All</a>
                <a href="tokens.php?filter=unused" class="px-3 py-1 rounded text-xs <?= $filter==='unused'?'bg-violet-600':'bg-gray-700/60 hover:bg-gray-700' ?>">Unused</a>
                <a href="tokens.php?filter=used" class="px-3 py-1 rounded text-xs <?= $filter==='used'?'bg-violet-600':'bg-gray-700/60 hover:bg-gray-700' ?>">Used</a>
            </div>
        </div>

        <?php if (!empty($error)): ?>
            <p class="text-red-400 text-sm mt-4"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>

        <?php if (isset($_GET['created'])): ?>
            <p class="text-green-400 text-sm mt-4">✅ <?= (int)$_GET['created'] ?> Token(s) erstellt.</p>
        <?php endif; ?>

        <?php if (isset($_GET['deleted'])): ?>
            <p class="text-green-400 text-sm mt-4">✅ Token gelöscht.</p>
        <?php endif; ?>

        <?php if (isset($_GET['deleted_count'])): ?>
            <p class="text-green-400 text-sm mt-4">✅ <?= (int)$_GET['deleted_count'] ?> Token(s) gelöscht.</p>
        <?php endif; ?>

        <?php if (isset($_GET['purged'])): ?>
            <p class="text-green-400 text-sm mt-4">✅ Tokens bereinigt: <?= htmlspecialchars($_GET['purged']) ?>.</p>
        <?php endif; ?>

        <!-- Bulk Delete Bar -->
        <div class="mt-6 flex items-center justify-between gap-4 flex-wrap">
            <div class="text-sm text-gray-400">
                <span id="selectedCount">0</span> ausgewählt
            </div>

            <form method="POST" id="bulkDeleteForm" onsubmit="return confirmBulkDelete();">
                <input type="hidden" name="bulk_delete_tokens" value="1">
                <button id="bulkDeleteBtn" type="submit"
                        class="px-4 py-2 rounded text-xs bg-gray-700/60 hover:bg-red-600 transition disabled:opacity-40 disabled:cursor-not-allowed"
                        disabled>
                    Delete Selected
                </button>
            </form>
        </div>

        <div class="mt-4 overflow-x-auto">
            <table class="w-full table-fixed text-sm text-gray-200">
                <colgroup>
                    <col style="width:56px">
                    <col style="width:520px">
                    <col style="width:90px">
                    <col style="width:110px">
                    <col style="width:90px">
                    <col style="width:220px">
                    <col style="width:220px">
                </colgroup>

                <thead class="text-gray-400 uppercase border-b border-gray-700/60">
                <tr>
                    <th class="px-6 py-2 text-left">
                        <input id="selectAll" type="checkbox" class="authly-token-check">
                    </th>
                    <th class="px-6 py-2 text-left">Token</th>
                    <th class="px-6 py-2 text-left">Days</th>
                    <th class="px-6 py-2 text-left">Rank ID</th>
                    <th class="px-6 py-2 text-left">Used</th>
                    <th class="px-6 py-2 text-left">Used By</th>
                    <th class="px-6 py-2 text-center">Actions</th>
                </tr>
                </thead>

                <tbody class="divide-y divide-gray-800">
                <?php if (empty($tokens)): ?>
                    <tr>
                        <td colspan="7" class="px-6 py-6 text-gray-500 italic">Keine Tokens gefunden.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($tokens as $t): ?>
                        <?php $used = (int)($t['used'] ?? 0) === 1; ?>
                        <tr class="hover:bg-gray-700/20 transition">
                            <td class="px-6 py-2">
                                <input type="checkbox"
                                       class="tokenCheckbox authly-token-check"
                                       value="<?= (int)$t['id'] ?>">
                            </td>

                            <td class="px-6 py-2 truncate font-mono text-xs"><?= htmlspecialchars($t['token'] ?? '') ?></td>
                            <td class="px-6 py-2"><?= (int)($t['days'] ?? 0) ?></td>
                            <td class="px-6 py-2"><?= (int)($t['rank_id'] ?? 0) ?></td>
                            <td class="px-6 py-2">
                                <?= $used
                                    ? "<span class='text-red-400 font-semibold text-xs'>YES</span>"
                                    : "<span class='text-green-400 font-semibold text-xs'>NO</span>" ?>
                            </td>
                            <td class="px-6 py-2 truncate text-xs text-gray-300">
                                <?= htmlspecialchars($t['used_by'] ?? '—') ?>
                            </td>

                            <td class="px-6 py-2 text-center whitespace-nowrap space-x-2">
                                <form method="POST" class="inline">
                                    <input type="hidden" name="toggle_used_token_id" value="<?= (int)$t['id'] ?>">
                                    <button
                                        class="text-xs px-3 py-1 rounded text-white <?= $used ? 'bg-yellow-600 hover:bg-yellow-700' : 'bg-green-600 hover:bg-green-700' ?>">
                                        <?= $used ? 'Set Unused' : 'Set Used' ?>
                                    </button>
                                </form>

                                <form method="POST" class="inline">
                                    <input type="hidden" name="delete_token_id" value="<?= (int)$t['id'] ?>">
                                    <button class="text-xs px-3 py-1 rounded bg-gray-700/60 hover:bg-red-600">
                                        Delete
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- CREATE TOKENS -->
    <section class="bg-gray-800 rounded-2xl p-6 shadow-xl" x-data="{ tokenType: '<?= htmlspecialchars($formType, ENT_QUOTES, 'UTF-8') ?>' }">
        <h2 class="text-xl font-semibold text-gray-100 mb-4">Tokens erstellen</h2>

        <form method="POST" class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div>
                <label class="text-gray-300 text-sm">Amount (max 500)</label>
                <input name="token_amount" type="number" min="1" max="500" value="10"
                       class="bg-gray-900 border border-gray-700 rounded px-3 py-2 w-full text-gray-100" required>
            </div>

            <div>
                <label class="text-gray-300 text-sm">Days</label>
                <input name="token_days" type="number" min="0" value="30"
                       class="bg-gray-900 border border-gray-700 rounded px-3 py-2 w-full text-gray-100" required>
            </div>

            <div>
                <label class="text-gray-300 text-sm">Rank ID</label>
                <input name="token_rank" type="number" min="0" value="0"
                       class="bg-gray-900 border border-gray-700 rounded px-3 py-2 w-full text-gray-100" required>
            </div>

            <div>
                <label class="text-gray-300 text-sm">Token Type</label>
                <select name="token_type"
                        class="bg-gray-900 border border-gray-700 rounded px-3 py-2 w-full text-gray-100"
                        x-model="tokenType">
                    <option value="normal">Normal ( <?= htmlspecialchars($presets['normal']) ?> )</option>
                    <option value="big_normal">Big Normal ( <?= htmlspecialchars($presets['big_normal']) ?> )</option>
                    <option value="guid">GUID ( <?= htmlspecialchars($presets['guid']) ?> )</option>
                    <option value="custom">Custom Mask ( { } = random X )</option>
                </select>
            </div>

            <div class="md:col-span-4" x-show="tokenType === 'custom'" x-cloak>
                <label class="text-gray-300 text-sm">Custom Mask</label>
                <input name="token_mask" value="<?= htmlspecialchars($formMask) ?>"
                       class="bg-gray-900 border border-gray-700 rounded px-3 py-2 w-full text-gray-100"
                       placeholder="z.B. Name-{XXXXX-XXXX}-{XX} oder X bleibt literal außerhalb {}">
                <p class="text-xs text-gray-400 mt-1">
                    Nur innerhalb von <span class="font-mono">{...}</span> werden <span class="font-mono">X</span> randomisiert.
                    Alles außerhalb bleibt exakt wie eingegeben (inkl. "X").
                </p>
            </div>

            <div class="md:col-span-4">
                <button type="submit" name="create_tokens"
                        class="bg-orange-600 hover:bg-orange-700 text-white px-6 py-2 rounded shadow text-sm">
                    CREATE TOKENS
                </button>
            </div>
        </form>
    </section>

    <!-- BULK ACTIONS -->
    <section class="bg-gray-800 rounded-2xl p-6 shadow-xl">
        <h2 class="text-xl font-semibold text-gray-100 mb-4">Bulk Actions</h2>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">

            <form method="POST" onsubmit="return confirm('Alle Tokens löschen?');">
                <button name="purge_all_tokens"
                        class="w-full bg-red-600 hover:bg-red-700 text-white px-4 py-3 rounded text-xs">
                    PURGE ALL TOKENS
                </button>
            </form>

            <form method="POST" onsubmit="return confirm('Alle USED Tokens löschen?');">
                <button name="purge_used_tokens"
                        class="w-full bg-gray-700 hover:bg-gray-600 text-white px-4 py-3 rounded text-xs">
                    PURGE USED TOKENS
                </button>
            </form>

            <form method="POST" onsubmit="return confirm('Alle UNUSED Tokens löschen?');">
                <button name="purge_unused_tokens"
                        class="w-full bg-gray-700 hover:bg-gray-600 text-white px-4 py-3 rounded text-xs">
                    PURGE UNUSED TOKENS
                </button>
            </form>

        </div>
    </section>

</main>

<script>
(function(){
    const selectAll = document.getElementById('selectAll');
    const boxes = Array.from(document.querySelectorAll('.tokenCheckbox'));
    const bulkForm = document.getElementById('bulkDeleteForm');
    const bulkBtn = document.getElementById('bulkDeleteBtn');
    const countEl = document.getElementById('selectedCount');

    function getSelectedIds() {
        return boxes.filter(b => b.checked).map(b => b.value);
    }

    function refreshUI() {
        const ids = getSelectedIds();
        countEl.textContent = String(ids.length);
        bulkBtn.disabled = ids.length === 0;

        const checked = ids.length;
        if (checked === 0) {
            selectAll.indeterminate = false;
            selectAll.checked = false;
        } else if (checked === boxes.length) {
            selectAll.indeterminate = false;
            selectAll.checked = true;
        } else {
            selectAll.indeterminate = true;
        }
    }

    selectAll?.addEventListener('change', () => {
        boxes.forEach(b => b.checked = selectAll.checked);
        refreshUI();
    });

    boxes.forEach(b => b.addEventListener('change', refreshUI));

    window.confirmBulkDelete = function() {
        const ids = getSelectedIds();
        if (ids.length === 0) return false;

        bulkForm.querySelectorAll('input[name="selected_token_ids[]"]').forEach(n => n.remove());

        ids.forEach(id => {
            const inp = document.createElement('input');
            inp.type = 'hidden';
            inp.name = 'selected_token_ids[]';
            inp.value = id;
            bulkForm.appendChild(inp);
        });

        return true;
    };

    refreshUI();
})();
</script>

</body>
</html>
