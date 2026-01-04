<?php
// ============================================================================
// AUTHLY – PROJECT USER ACTIONS (AJAX)
// PFAD: /functions/user_actions.php
// ============================================================================

require_once __DIR__ . '/../db/config.php';
require_once __DIR__ . '/dbfunctions.php';
require_once __DIR__ . '/db_project_functions.php';

session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not logged in']);
    exit;
}

if (!isset($_SESSION['active_project']) || !is_numeric($_SESSION['active_project'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'No active project']);
    exit;
}

$ownerId   = (int)($_SESSION['user_id'] ?? 0);
$projectId = (int)($_SESSION['active_project'] ?? 0);

// Ownership prüfen
$project = getProjectByIdAndOwner($projectId, $ownerId);
if (!$project) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Access denied']);
    exit;
}

function dtlocal_to_mysql(?string $dtlocal): ?string {
    if ($dtlocal === null) return null;
    $dtlocal = trim($dtlocal);
    if ($dtlocal === '') return null;
    $dtlocal = str_replace('T', ' ', $dtlocal);
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $dtlocal)) {
        $dtlocal .= ':00';
    }
    return $dtlocal;
}

$action = (string)($_POST['action'] ?? '');
$action = trim($action);

if ($action === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid request']);
    exit;
}

try {
    // =========================================================================
    // BULK ACTIONS (kein user-id nötig)
    // =========================================================================
    if (str_starts_with($action, 'bulk_')) {
        global $pdo;

        switch ($action) {
            case 'bulk_pause_sub': {
                $st = $pdo->prepare("UPDATE project_users SET subscription_paused = 1 WHERE project_id = :pid");
                $st->execute([':pid' => $projectId]);
                echo json_encode(['ok' => true, 'message' => 'All subscriptions paused', 'affected' => (int)$st->rowCount()]);
                exit;
            }
            case 'bulk_unpause_sub': {
                $st = $pdo->prepare("UPDATE project_users SET subscription_paused = 0 WHERE project_id = :pid");
                $st->execute([':pid' => $projectId]);
                echo json_encode(['ok' => true, 'message' => 'All subscriptions unpaused', 'affected' => (int)$st->rowCount()]);
                exit;
            }
            case 'bulk_reset_hwid': {
                $st = $pdo->prepare("UPDATE project_users SET hwid = NULL WHERE project_id = :pid");
                $st->execute([':pid' => $projectId]);
                echo json_encode(['ok' => true, 'message' => 'All HWIDs reset', 'affected' => (int)$st->rowCount()]);
                exit;
            }
            case 'bulk_purge_users': {
                // Achtung: löscht alle Projekt-User (Tokens bleiben unberührt)
                $st = $pdo->prepare("DELETE FROM project_users WHERE project_id = :pid");
                $st->execute([':pid' => $projectId]);
                echo json_encode(['ok' => true, 'message' => 'All users deleted', 'affected' => (int)$st->rowCount()]);
                exit;
            }
            default:
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Unknown bulk action']);
                exit;
        }
    }

    // =========================================================================
    // SINGLE USER ACTIONS
    // =========================================================================
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid user id']);
        exit;
    }

    // Sicherstellen, dass User zu diesem Projekt gehört
    $u = getProjectUser($id, $projectId);
    if (!$u) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'User not found']);
        exit;
    }

    switch ($action) {
        case 'save': {
            $fields = [];

            if (isset($_POST['username'])) {
                $username = trim((string)$_POST['username']);
                if ($username === '') throw new RuntimeException('Username darf nicht leer sein.');
                $fields['username'] = $username;
            }

            if (array_key_exists('email', $_POST)) {
                $email = trim((string)$_POST['email']);
                $fields['email'] = ($email === '') ? null : $email;
            }

            if (array_key_exists('password', $_POST)) {
                $pw = (string)$_POST['password'];
                if (trim($pw) !== '') {
                    $fields['password'] = password_hash($pw, PASSWORD_ARGON2ID);
                }
            }

            if (array_key_exists('hwid', $_POST)) {
                $hwid = trim((string)$_POST['hwid']);
                $fields['hwid'] = ($hwid === '') ? null : $hwid;
            }

            // ✅ UserVar speichern (DB-Spalte heißt: UserVar)
            if (array_key_exists('uservar', $_POST)) {
                $uv = (string)$_POST['uservar'];
                $uv = trim($uv);
                $fields['UserVar'] = ($uv === '') ? null : $uv;
            }

            if (array_key_exists('expires', $_POST)) {
                $fields['expires_at'] = dtlocal_to_mysql((string)$_POST['expires']);
            }

            if (array_key_exists('ip', $_POST)) {
                $ip = trim((string)$_POST['ip']);
                $fields['ip'] = ($ip === '') ? null : $ip;
            }

            // Rank bearbeiten
            if (array_key_exists('rank_id', $_POST)) {
                $rankId = (int)$_POST['rank_id'];
                if ($rankId < 0) $rankId = 0;
                $fields['Rank_id'] = $rankId;
            }

            if (empty($fields)) {
                echo json_encode(['ok' => true, 'message' => 'Nothing to update']);
                exit;
            }

            // updateProjectUser() falls vorhanden, sonst Fallback
            if (function_exists('updateProjectUser')) {
                $ok = updateProjectUser($id, $projectId, $fields);
            } else {
                global $pdo;
                $sets = [];
                $params = [':uid' => $id, ':pid' => $projectId];
                foreach ($fields as $k => $v) {
                    $sets[] = "`$k` = :$k";
                    $params[":$k"] = $v;
                }
                $sql = "UPDATE project_users SET " . implode(', ', $sets) . " WHERE id = :uid AND project_id = :pid";
                $stmt = $pdo->prepare($sql);
                $ok = $stmt->execute($params);
            }

            echo json_encode(['ok' => (bool)$ok, 'message' => $ok ? 'Saved' : 'Save failed']);
            exit;
        }

        case 'reset_hwid': {
            $ok = resetProjectUserHWID($id, $projectId);
            echo json_encode(['ok' => (bool)$ok, 'message' => $ok ? 'HWID reset' : 'HWID reset failed']);
            exit;
        }

        // ✅ Subscription Pause/Unpause
        case 'toggle_sub': {
            global $pdo;

            $cur = (int)($u['subscription_paused'] ?? 0);
            $new = $cur === 1 ? 0 : 1;

            $st = $pdo->prepare("UPDATE project_users SET subscription_paused = :v WHERE id = :uid AND project_id = :pid");
            $ok = $st->execute([':v' => $new, ':uid' => $id, ':pid' => $projectId]);

            echo json_encode([
                'ok' => (bool)$ok,
                'message' => $ok ? ($new ? 'Subscription paused' : 'Subscription unpaused') : 'Toggle failed',
                'subscription_paused' => $new
            ]);
            exit;
        }

        case 'toggle_ban': {
            if (function_exists('toggleProjectUserBan')) {
                $new = toggleProjectUserBan($id, $projectId);
                if ($new === null) {
                    echo json_encode(['ok' => false, 'error' => 'Toggle failed']);
                    exit;
                }
                echo json_encode(['ok' => true, 'message' => $new ? 'User banned' : 'User unbanned', 'banned' => $new]);
                exit;
            }

            $isBanned = (int)($u['banned'] ?? 0) === 1;
            $ok = $isBanned ? unbanProjectUser($id, $projectId) : banProjectUser($id, $projectId);
            echo json_encode(['ok' => (bool)$ok, 'message' => $ok ? ($isBanned ? 'User unbanned' : 'User banned') : 'Toggle failed', 'banned' => $isBanned ? 0 : 1]);
            exit;
        }

        case 'delete': {
            $ok = deleteProjectUser($id, $projectId);
            echo json_encode(['ok' => (bool)$ok, 'message' => $ok ? 'Deleted' : 'Delete failed']);
            exit;
        }

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Unknown action']);
            exit;
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
}
