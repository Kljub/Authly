<?php
// ============================================================================
// PFAD: /functions/admin_functions/admin_db.php
// ----------------------------------------------------------------------------
// Liefert PDO (config.php hat nur Konstanten)
// ============================================================================

function admin_get_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        return $pdo;
    } catch (Throwable $e) {
        http_response_code(500);
        die("<p style='color:red;font-size:20px;text-align:center;margin-top:60px;'>❌ DB Verbindung fehlgeschlagen.</p>");
    }
}

