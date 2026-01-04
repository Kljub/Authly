<?php
// ============================================================================
// PFAD: /functions/cms_pages.php
// ----------------------------------------------------------------------------
// Basic CMS Pages (CRUD helpers)
// ============================================================================

function cms_slugify(string $s): string {
    $s = trim(mb_strtolower($s));
    $s = preg_replace('/[^\p{L}\p{N}]+/u', '-', $s);
    $s = trim($s, '-');
    if ($s === '') $s = 'page';
    return mb_substr($s, 0, 140);
}

function cms_sanitize_html(string $html): string {
    // Minimaler Allowlist-Filter (kein Full HTMLPurifier)
    // Erlaubt gängige Tags, strippt den Rest.
    $allowed = '<h1><h2><h3><h4><p><br><b><strong><i><em><u><ul><ol><li><a><code><pre><blockquote><hr><span><div>';
    $clean = strip_tags($html, $allowed);

    // Entferne on* handler (onclick etc.) grob
    $clean = preg_replace('/\son\w+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/i', '', $clean);

    // Entferne javascript: in href
    $clean = preg_replace('/href\s*=\s*([\'"])\s*javascript:.*?\1/i', 'href="#"', $clean);

    return $clean;
}

function cms_get_page(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM cms_pages WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function cms_get_page_by_slug(PDO $pdo, string $slug): ?array {
    $stmt = $pdo->prepare("SELECT * FROM cms_pages WHERE slug = ? LIMIT 1");
    $stmt->execute([$slug]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function cms_list_pages(PDO $pdo): array {
    return $pdo->query("SELECT id, slug, title, status, created_at, updated_at FROM cms_pages ORDER BY id DESC")
               ->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function cms_upsert_page(PDO $pdo, ?int $id, string $slug, string $title, string $content, string $status): int {
    $slug = cms_slugify($slug);
    $title = trim($title);
    if ($title === '') $title = 'Untitled';

    $status = in_array($status, ['draft','published'], true) ? $status : 'draft';
    $content = cms_sanitize_html($content);

    if ($id && $id > 0) {
        $stmt = $pdo->prepare("UPDATE cms_pages SET slug=?, title=?, content=?, status=?, updated_at=NOW() WHERE id=?");
        $stmt->execute([$slug, $title, $content, $status, $id]);
        return $id;
    }

    $stmt = $pdo->prepare("INSERT INTO cms_pages (slug, title, content, status, created_at, updated_at)
                           VALUES (?, ?, ?, ?, NOW(), NOW())");
    $stmt->execute([$slug, $title, $content, $status]);
    return (int)$pdo->lastInsertId();
}

function cms_delete_page(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare("DELETE FROM cms_pages WHERE id = ?");
    $stmt->execute([$id]);
}
