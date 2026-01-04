<?php
// PFAD: /index.php
require_once __DIR__ . '/db/config.php';
require_once __DIR__ . '/functions/site_settings.php';
require_once __DIR__ . '/functions/cms_pages.php';
require_once __DIR__ . '/functions/page_builder_render.php';

session_start();

function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

// DB connect wie bei dir üblich
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    die("DB Verbindung fehlgeschlagen.");
}

$isLoggedIn = isset($_SESSION['user_id']);

$mode   = (string)site_get($pdo, 'homepage_mode', 'page');
$pageId = (string)site_get($pdo, 'homepage_page_id', '');

// Optional: direkte Page per slug
$slug = isset($_GET['page']) ? trim((string)$_GET['page']) : '';
$page = null;

if ($slug !== '') {
    // nur published für public
    $stmt = $pdo->prepare("SELECT * FROM cms_pages WHERE slug=? AND status='published' LIMIT 1");
    $stmt->execute([$slug]);
    $page = $stmt->fetch() ?: null;
} else {
    // homepage behavior
    if ($mode === 'redirect_login') { header("Location: /login/"); exit; }
    if ($mode === 'dashboard_if_logged_in' && $isLoggedIn) { header("Location: /dashboard/"); exit; }

    if ($pageId !== '' && ctype_digit($pageId)) {
        $page = cms_get_page($pdo, (int)$pageId);
    }
    if (!$page) {
        $page = $pdo->query("SELECT * FROM cms_pages WHERE status='published' ORDER BY id ASC LIMIT 1")->fetch() ?: null;
    }
}

if (!$page || (string)$page['status'] !== 'published') {
    http_response_code(404);
    echo "Page nicht gefunden.";
    exit;
}

// Render output based on render_mode
$renderMode = (string)($page['render_mode'] ?? 'builder');
$contentHtml = '';

if ($renderMode === 'html') {
    $contentHtml = (string)($page['content'] ?? '');
} else {
    $contentHtml = pb_render_builder_json((string)($page['builder_json'] ?? ''));
    if ($contentHtml === '') {
        // fallback, falls json leer
        $contentHtml = (string)($page['content'] ?? '');
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title><?= h((string)$page['title']) ?> – Authly</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="/gui/css/style.css" rel="stylesheet">
</head>
<body class="bg-gray-900 text-gray-200 font-inter">
  <div class="max-w-5xl mx-auto px-6 py-10">
    <div class="flex items-center justify-between mb-8">
      <div class="text-xl font-bold text-gray-100">Authly</div>
      <div class="flex gap-3">
        <?php if ($isLoggedIn): ?>
          <a class="px-4 py-2 rounded-xl bg-violet-600 hover:bg-violet-500 transition text-white font-semibold" href="/dashboard/">Dashboard</a>
        <?php else: ?>
          <a class="px-4 py-2 rounded-xl bg-gray-800 hover:bg-gray-700 transition text-white font-semibold" href="/login/">Login</a>
          <a class="px-4 py-2 rounded-xl bg-violet-600 hover:bg-violet-500 transition text-white font-semibold" href="/register/">Register</a>
        <?php endif; ?>
      </div>
    </div>

    <article class="space-y-4">
      <?= $contentHtml ?>
    </article>
  </div>
</body>
</html>
