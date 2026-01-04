<?php
// ============================================================================
// PFAD: /functions/admin_functions/admin_auth.php
// ============================================================================
function require_admin(): array
{
    if (!isset($_SESSION['user_id'])) {
        header("Location: /login/");
        exit();
    }

    $userRole = (string)($_SESSION['role_id'] ?? '2');
    $userName = (string)($_SESSION['username'] ?? '');
    $ownerId  = (int)($_SESSION['user_id'] ?? 0);

    $roleNorm = function_exists('normalize_role')
        ? normalize_role((string)$userRole)
        : (((string)$userRole === '1') ? 'admin' : 'user');

    if ($roleNorm !== 'admin') {
        http_response_code(403);
        die("<p style='color:red;font-size:20px;text-align:center;margin-top:60px;'>❌ Zugriff verweigert (Admin only).</p>");
    }

    return [
        'role_id'  => $userRole,
        'username' => $userName,
        'user_id'  => $ownerId,
        'roleNorm' => $roleNorm,
    ];
}
