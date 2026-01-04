<?php
// ============================================================================
// PFAD: /functions/site_settings.php
// ----------------------------------------------------------------------------
// Simple Key/Value Settings Store (DB)
// ============================================================================

function site_get(PDO $pdo, string $key, $default = null) {
    $stmt = $pdo->prepare("SELECT value FROM site_settings WHERE `key` = ? LIMIT 1");
    $stmt->execute([$key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return $default;
    return $row['value'];
}

function site_set(PDO $pdo, string $key, $value): void {
    $stmt = $pdo->prepare("
        INSERT INTO site_settings (`key`,`value`) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)
    ");
    $stmt->execute([$key, $value]);
}
