<?php
// ============================================================================
// AUTHLY – LOGOUT
// PFAD: /logout.php
// ============================================================================

session_start();

// Alle Session-Variablen löschen
$_SESSION = [];

// Session-Cookie ungültig machen (falls vorhanden)
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// Session endgültig zerstören
session_destroy();

// Optional: zusätzliche Sicherheit
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Zur Login-Seite weiterleiten
header('Location: /login/');
exit;
