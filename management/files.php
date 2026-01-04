<?php
// ============================================================================
// AUTHLY – PROJECT FILES MANAGEMENT (UI)
// PFAD: /management/files.php
// ============================================================================

require_once __DIR__ . '/../db/config.php';
require_once __DIR__ . '/../functions/menu.php';
require_once __DIR__ . '/../functions/icons.php';
require_once __DIR__ . '/../functions/dbfunctions.php';
require_once __DIR__ . '/../functions/file_manager.php';

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

$isOwner = ((int)($project['owner_id'] ?? 0) === (int)$ownerId);

$errorMessage = '';

// -----------------------------------------------------------------------------
// DOWNLOAD (GET) – liefert decrypted bytes als Datei (Owner-only)
// -----------------------------------------------------------------------------
if (isset($_GET['download']) && is_numeric($_GET['download'])) {
    if (!$isOwner) {
        http_response_code(403);
        die("Forbidden");
    }

    $fileId = (int)$_GET['download'];
    $row = getProjectFile($projectId, $fileId);
    if (!$row) {
        http_response_code(404);
        die("Not found");
    }

    $plain = decryptProjectFileContent($projectId, (string)($row['content_enc'] ?? ''));
    if ($plain === null) {
        http_response_code(500);
        die("Decrypt failed");
    }

    $mime = (string)($row['mime_type'] ?? 'application/octet-stream');
    $name = (string)($row['original_name'] ?? $row['file_name'] ?? 'download.bin');
    if ($name === '') $name = 'download.bin';

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . strlen($plain));
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $name) . '"');
    echo $plain;
    exit;
}

// -----------------------------------------------------------------------------
// ACTIONS (POST)
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Upload/create
    if (isset($_POST['create_file'])) {
        if (!$isOwner) {
            $errorMessage = "❌ Nur der Projekt-Owner kann Files hochladen.";
        } else {
            $fileKey = trim((string)($_POST['file_name'] ?? ''));
            if ($fileKey === '') {
                $errorMessage = "❌ Bitte einen File-Namen (Key) angeben.";
            } elseif (!isset($_FILES['file_upload'])) {
                $errorMessage = "❌ Keine Datei ausgewählt.";
            } else {
                $ok = createProjectFileFromUpload($projectId, $fileKey, $_FILES['file_upload']);
                if ($ok) {
                    header("Location: files.php?created=1");
                    exit;
                }
                $err = authly_file_last_error();
                $errorMessage = $err !== '' ? ("❌ " . $err) : "❌ Upload fehlgeschlagen.";
            }
        }
    }

    // Delete
    if (isset($_POST['delete_file_id'])) {
        if (!$isOwner) {
            $errorMessage = "❌ Nur der Projekt-Owner kann Files löschen.";
        } else {
            $fid = (int)($_POST['delete_file_id'] ?? 0);
            if ($fid > 0) deleteProjectFile($projectId, $fid);
            header("Location: files.php?deleted=1");
            exit;
        }
    }
}

// Files laden
$files = getProjectFiles($projectId);

// Owner-only: encrypted preview
if ($isOwner) {
    foreach ($files as &$f) {
        $f['enc_preview'] = (string)($f['content_enc'] ?? '');
        if (strlen($f['enc_preview']) > 90) $f['enc_preview'] = substr($f['enc_preview'], 0, 90) . '…';
    }
    unset($f);
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Project Files – <?= htmlspecialchars($project['name'] ?? '') ?></title>

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

    <!-- FILES TABLE -->
    <section class="bg-gray-800 rounded-2xl p-6 shadow-xl"
             x-data="{
                q: '',
                norm(s){ return (s ?? '').toString().toLowerCase().trim(); },
                match(fileKey, original, mime, size, enc){
                    const query = this.norm(this.q);
                    if(!query) return true;
                    const hay = [fileKey, original, mime, String(size), enc].map(x => this.norm(x)).join(' ');
                    return hay.includes(query);
                }
             }">

        <div class="flex items-start justify-between gap-6 flex-wrap">
            <div>
                <h1 class="text-2xl font-bold text-gray-100 mb-1">Files Management :</h1>
                <p class="text-gray-400">Project Files (verschlüsselt gespeichert).</p>
            </div>

            <!-- SEARCH -->
            <div class="w-full sm:w-auto flex items-center gap-2">
                <div class="relative w-full sm:w-[420px]">
                    <input
                        x-model="q"
                        type="text"
                        placeholder="Suchen (Key, Name, MIME<?= $isOwner ? ', encrypted' : '' ?>)…"
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

        <?php if (isset($_GET['created'])): ?>
            <p class="text-green-400 text-sm mt-4">✅ File erstellt.</p>
        <?php endif; ?>
        <?php if (isset($_GET['deleted'])): ?>
            <p class="text-green-400 text-sm mt-4">✅ File gelöscht.</p>
        <?php endif; ?>
        <?php if (!empty($errorMessage)): ?>
            <p class="text-red-400 text-sm mt-4"><?= htmlspecialchars($errorMessage) ?></p>
        <?php endif; ?>

        <?php if (!$isOwner): ?>
            <p class="text-yellow-300 text-sm mt-4">⚠️ Hinweis: Datei-Inhalte/Downloads sind nur für den Projekt-Owner sichtbar.</p>
        <?php endif; ?>

        <div class="mt-6 overflow-x-auto">
            <table class="w-full table-fixed text-sm text-gray-200">
                <colgroup>
                    <col style="width:240px">
                    <col style="width:240px">
                    <col style="width:160px">
                    <col style="width:140px">
                    <?php if ($isOwner): ?>
                        <col style="width:auto">
                        <col style="width:240px">
                    <?php else: ?>
                        <col style="width:220px">
                    <?php endif; ?>
                </colgroup>

                <thead class="text-gray-400 uppercase border-b border-gray-700/60">
                <tr>
                    <th class="px-6 py-2 text-left">File Key</th>
                    <th class="px-6 py-2 text-left">Original Name</th>
                    <th class="px-6 py-2 text-left">MIME</th>
                    <th class="px-6 py-2 text-left">Size</th>
                    <?php if ($isOwner): ?>
                        <th class="px-6 py-2 text-left">Encrypted (preview)</th>
                        <th class="px-6 py-2 text-right">Actions</th>
                    <?php else: ?>
                        <th class="px-6 py-2 text-right">Actions</th>
                    <?php endif; ?>
                </tr>
                </thead>

                <tbody class="divide-y divide-gray-800">
                <?php if (empty($files)): ?>
                    <tr>
                        <td colspan="<?= $isOwner ? 6 : 5 ?>" class="px-6 py-6 text-gray-500 italic">Keine Files gefunden.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($files as $f): ?>
                        <?php
                        $fileKey  = (string)($f['file_name'] ?? '');
                        $origName = (string)($f['original_name'] ?? '—');
                        $mimeType = (string)($f['mime_type'] ?? '—');
                        $sizeB    = (int)($f['file_size'] ?? 0);
                        $encFull  = (string)($f['content_enc'] ?? '');
                        $encPrev  = (string)($f['enc_preview'] ?? '');
                        $fid      = (int)($f['id'] ?? 0);
                        ?>
                        <tr
                            x-show="match('<?= htmlspecialchars($fileKey, ENT_QUOTES, 'UTF-8') ?>',
                                          '<?= htmlspecialchars($origName, ENT_QUOTES, 'UTF-8') ?>',
                                          '<?= htmlspecialchars($mimeType, ENT_QUOTES, 'UTF-8') ?>',
                                          <?= (int)$sizeB ?>,
                                          '<?= htmlspecialchars($isOwner ? $encFull : '', ENT_QUOTES, 'UTF-8') ?>')"
                            x-cloak
                            class="hover:bg-gray-700/20 transition">

                            <td class="px-6 py-3 font-semibold"><?= htmlspecialchars($fileKey) ?></td>
                            <td class="px-6 py-3 truncate"><?= htmlspecialchars($origName) ?></td>
                            <td class="px-6 py-3 text-xs text-gray-300"><?= htmlspecialchars($mimeType) ?></td>
                            <td class="px-6 py-3 text-xs text-gray-300"><?= (int)$sizeB ?> B</td>

                            <?php if ($isOwner): ?>
                                <td class="px-6 py-3 font-mono text-xs text-gray-400 truncate"
                                    title="<?= htmlspecialchars($encFull) ?>">
                                    <?= htmlspecialchars($encPrev) ?>
                                </td>

                                <td class="px-6 py-3 text-right whitespace-nowrap space-x-2">
                                    <a href="files.php?download=<?= $fid ?>"
                                       class="text-xs px-3 py-1 rounded bg-blue-600/80 hover:bg-blue-600">
                                        Download
                                    </a>

                                    <form method="POST" class="inline" onsubmit="return confirm('File wirklich löschen?');">
                                        <input type="hidden" name="delete_file_id" value="<?= $fid ?>">
                                        <button class="text-xs px-3 py-1 rounded bg-gray-700/60 hover:bg-red-600">
                                            Delete
                                        </button>
                                    </form>
                                </td>
                            <?php else: ?>
                                <td class="px-6 py-3 text-right text-xs text-gray-500">—</td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

    </section>

    <!-- UPLOAD FILE -->
    <section class="bg-gray-800 rounded-2xl p-6 shadow-xl">
        <h2 class="text-xl font-semibold text-gray-100 mb-4">File hochladen</h2>

        <?php if (!$isOwner): ?>
            <p class="text-yellow-300 text-sm">Nur der Projekt-Owner kann Files hochladen.</p>
        <?php else: ?>
            <form method="POST" enctype="multipart/form-data" class="grid grid-cols-1 md:grid-cols-6 gap-4">
                <div class="md:col-span-2">
                    <label class="text-gray-300 text-sm">File Key (Name)</label>
                    <input name="file_name"
                           class="bg-gray-900 border border-gray-700 rounded px-3 py-2 w-full text-gray-100"
                           placeholder="z.B. sdk_update_zip"
                           required>
                </div>

                <div class="md:col-span-4">
                    <label class="text-gray-300 text-sm">Datei</label>
                    <input type="file" name="file_upload"
                           class="bg-gray-900 border border-gray-700 rounded px-3 py-2 w-full text-gray-100"
                           required>
                </div>

                <div class="md:col-span-6">
                    <button type="submit" name="create_file"
                            class="bg-orange-600 hover:bg-orange-700 text-white px-6 py-2 rounded shadow text-sm">
                        UPLOAD (encrypted)
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </section>

</main>

</body>
</html>
