<?php
// ============================================================================
// DB-FUNCTIONS – zentrale Datenbankfunktionen für Authly Dashboard
// PFAD: /functions/dbfunctions.php
// ============================================================================

require_once __DIR__ . '/../db/config.php';

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (PDOException $e) {
    die('❌ DB-Verbindung fehlgeschlagen: ' . htmlspecialchars($e->getMessage()));
}

// -----------------------------------------------------------------------------
// ROLE / LIMIT HELPERS
// -----------------------------------------------------------------------------

function getUserRoleId(int $userId): int {
    global $pdo;

    if (isset($_SESSION['role_id']) && is_numeric($_SESSION['role_id'])) {
        return (int)$_SESSION['role_id'];
    }

    try {
        $stmt = $pdo->prepare("SELECT role_id FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $userId]);
        $rid = $stmt->fetchColumn();
        return $rid !== false ? (int)$rid : 1;
    } catch (PDOException $e) {
        error_log("DB Error [getUserRoleId]: " . $e->getMessage());
        return 1;
    }
}

function getRoleInfoById(int $roleId): array {
    global $pdo;

    try {
        $stmt = $pdo->prepare("SELECT id, slug, project_limit FROM roles WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $roleId]);
        $row = $stmt->fetch();

        if (!$row) {
            return ['id' => 1, 'slug' => 'user', 'project_limit' => 3];
        }

        return [
            'id'            => (int)$row['id'],
            'slug'          => (string)$row['slug'],
            'project_limit' => (int)$row['project_limit'],
        ];
    } catch (PDOException $e) {
        error_log("DB Error [getRoleInfoById]: " . $e->getMessage());
        return ['id' => 1, 'slug' => 'user', 'project_limit' => 3];
    }
}

function canUserCreateProject(int $userId): array {
    $roleId = getUserRoleId($userId);
    $role   = getRoleInfoById($roleId);

    $limit   = (int)($role['project_limit'] ?? 0);
    $slug    = strtolower((string)($role['slug'] ?? ''));
    $isAdmin = ($slug === 'admin') || ($limit >= 999);

    $current = getProjectCountByOwner($userId);

    if ($isAdmin) {
        return ['ok' => true, 'current' => $current, 'limit' => $limit, 'is_admin' => true];
    }

    if ($limit <= 0) {
        return ['ok' => false, 'current' => $current, 'limit' => $limit, 'is_admin' => false];
    }

    return ['ok' => ($current < $limit), 'current' => $current, 'limit' => $limit, 'is_admin' => false];
}

// -----------------------------------------------------------------------------
// Projekte count
// -----------------------------------------------------------------------------
function getProjectCountByOwner(int $ownerId): int {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM projects WHERE owner_id = :owner_id");
        $stmt->execute([':owner_id' => $ownerId]);
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("DB Error [getProjectCountByOwner]: " . $e->getMessage());
        return 0;
    }
}

function getLastLoginByUserId(int $userId): ?string {
    global $pdo;

    try {
        $stmt = $pdo->prepare("SELECT last_login FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $userId]);
        $result = $stmt->fetchColumn();
        return $result ?: null;
    } catch (PDOException $e) {
        error_log("DB Error [getLastLoginByUserId]: " . $e->getMessage());
        return null;
    }
}

// -----------------------------------------------------------------------------
// PROJECT SETTINGS (ausgelagert aus projects)
// -----------------------------------------------------------------------------

function ensureProjectSettingsRow(int $projectId): bool {
    global $pdo;

    $defaultPolicy = json_encode([
        "min_length" => 10,
        "require_uppercase" => true,
        "require_lowercase" => true,
        "require_number" => true,
        "require_symbol" => false
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    try {
        $stmt = $pdo->prepare("
            INSERT INTO project_settings (
                project_id,
                download_link,
                session_expiration,
                killswitch_enabled,
                hwid_enabled,
                dev_mode,
                status,

                allow_register,
                email_verification_required,
                password_policy,
                max_login_attempts,
                captcha_enabled,
                login_cooldown_seconds,
                force_logout_on_password_change
            )
            VALUES (
                :pid,
                NULL,
                15,
                0,
                0,
                0,
                'active',

                1,
                0,
                :pp,
                5,
                0,
                300,
                1
            )
            ON DUPLICATE KEY UPDATE project_id = project_id
        ");
        return $stmt->execute([
            ':pid' => $projectId,
            ':pp'  => $defaultPolicy
        ]);
    } catch (PDOException $e) {
        error_log("DB Error [ensureProjectSettingsRow]: " . $e->getMessage());
        return false;
    }
}

function getProjectSettings(int $projectId, int $ownerId): ?array {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT ps.*
            FROM project_settings ps
            INNER JOIN projects p ON p.id = ps.project_id
            WHERE ps.project_id = :pid AND p.owner_id = :oid
            LIMIT 1
        ");
        $stmt->execute([':pid' => $projectId, ':oid' => $ownerId]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (PDOException $e) {
        error_log("DB Error [getProjectSettings]: " . $e->getMessage());
        return null;
    }
}

function updateProjectSettings(int $projectId, int $ownerId, array $fields): bool {
    global $pdo;
    if (empty($fields)) return false;

    try {
        $chk = $pdo->prepare("SELECT 1 FROM projects WHERE id = :pid AND owner_id = :oid LIMIT 1");
        $chk->execute([':pid' => $projectId, ':oid' => $ownerId]);
        if (!$chk->fetchColumn()) return false;

        ensureProjectSettingsRow($projectId);

        $allowed = [
            'download_link',
            'session_expiration',
            'killswitch_enabled',
            'hwid_enabled',
            'dev_mode',
            'status',

            'allow_register',
            'email_verification_required',
            'password_policy',
            'max_login_attempts',
            'captcha_enabled',
            'login_cooldown_seconds',
            'force_logout_on_password_change',
        ];

        $set = [];
        $params = [':project_id' => $projectId];

        foreach ($fields as $key => $value) {
            if (!in_array($key, $allowed, true)) continue;
            $set[] = "`$key` = :$key";
            $params[":$key"] = $value;
        }

        if (empty($set)) return false;

        $sql = "UPDATE project_settings SET " . implode(', ', $set) . " WHERE project_id = :project_id LIMIT 1";
        $stmt = $pdo->prepare($sql);
        return $stmt->execute($params);

    } catch (PDOException $e) {
        error_log("DB Error [updateProjectSettings]: " . $e->getMessage());
        return false;
    }
}

// -----------------------------------------------------------------------------
// Projekte eines Benutzers abrufen (mit status aus project_settings)
// -----------------------------------------------------------------------------
function getProjectsByOwner(int $ownerId): array {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT
                p.id,
                p.name,
                p.version,
                COALESCE(ps.status, 'active') AS status,
                p.api_key,
                p.api_secret,
                p.created_at,
                p.updated_at
            FROM projects p
            LEFT JOIN project_settings ps ON ps.project_id = p.id
            WHERE p.owner_id = :owner_id
            ORDER BY p.created_at DESC
        ");
        $stmt->execute([':owner_id' => $ownerId]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("DB Error [getProjectsByOwner]: " . $e->getMessage());
        return [];
    }
}

// -----------------------------------------------------------------------------
// Neues Projekt erstellen (projects + project_settings)
// -----------------------------------------------------------------------------
function createProject(
    int $ownerId,
    string $name,
    string $apiKey,
    string $apiSecret,
    string $version = '1.0',
    ?string $downloadLink = null
): bool {
    global $pdo;

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO projects (owner_id, name, api_key, api_secret, version)
            VALUES (:owner_id, :name, :api_key, :api_secret, :version)
        ");
        $ok = $stmt->execute([
            ':owner_id'   => $ownerId,
            ':name'       => $name,
            ':api_key'    => $apiKey,
            ':api_secret' => $apiSecret,
            ':version'    => $version,
        ]);

        if (!$ok) {
            $pdo->rollBack();
            return false;
        }

        $projectId = (int)$pdo->lastInsertId();

        $ok2 = ensureProjectSettingsRow($projectId);
        if (!$ok2) {
            $pdo->rollBack();
            return false;
        }

        if ($downloadLink !== null && $downloadLink !== '') {
            $ok3 = updateProjectSettings($projectId, $ownerId, ['download_link' => $downloadLink]);
            if (!$ok3) {
                $pdo->rollBack();
                return false;
            }
        }

        $pdo->commit();
        return true;

    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("DB Error [createProject]: " . $e->getMessage());
        return false;
    }
}

// -----------------------------------------------------------------------------
// Projekt aktualisieren (NUR projects)
// -----------------------------------------------------------------------------
function updateProject(int $projectId, int $ownerId, array $fields): bool {
    global $pdo;

    $allowed = ['name', 'version', 'api_secret'];

    try {
        $set = [];
        $params = [':id' => $projectId, ':owner_id' => $ownerId];

        foreach ($fields as $key => $value) {
            if (!in_array($key, $allowed, true)) continue;
            $set[] = "`$key` = :$key";
            $params[":$key"] = $value;
        }

        if (empty($set)) return false;

        $sql = "UPDATE projects SET " . implode(', ', $set) . " WHERE id = :id AND owner_id = :owner_id";
        $stmt = $pdo->prepare($sql);
        return $stmt->execute($params);

    } catch (PDOException $e) {
        error_log("DB Error [updateProject]: " . $e->getMessage());
        return false;
    }
}

function deleteProject(int $projectId, int $ownerId): bool {
    global $pdo;
    try {
        $stmt = $pdo->prepare("DELETE FROM projects WHERE id = :id AND owner_id = :owner_id");
        return $stmt->execute([
            ':id' => $projectId,
            ':owner_id' => $ownerId
        ]);
    } catch (PDOException $e) {
        error_log("DB Error [deleteProject]: " . $e->getMessage());
        return false;
    }
}

// -----------------------------------------------------------------------------
// Projektstatus ändern
// active  = normal
// disabled= API OFF (nichts geht)
// archived= API ON (läuft weiter), aber Projekt "archiviert" im Dashboard
// -----------------------------------------------------------------------------
function toggleProjectStatus(int $projectId, int $ownerId, string $newStatus): bool {
    global $pdo;

    $allowed = ['active', 'disabled', 'archived'];
    if (!in_array($newStatus, $allowed, true)) return false;

    try {
        $chk = $pdo->prepare("SELECT 1 FROM projects WHERE id = :pid AND owner_id = :oid LIMIT 1");
        $chk->execute([':pid' => $projectId, ':oid' => $ownerId]);
        if (!$chk->fetchColumn()) return false;

        ensureProjectSettingsRow($projectId);

        $stmt = $pdo->prepare("
            UPDATE project_settings
            SET status = :status, updated_at = NOW()
            WHERE project_id = :pid
            LIMIT 1
        ");
        return $stmt->execute([
            ':status' => $newStatus,
            ':pid'    => $projectId
        ]);
    } catch (PDOException $e) {
        error_log("DB Error [toggleProjectStatus]: " . $e->getMessage());
        return false;
    }
}

// -----------------------------------------------------------------------------
// Dashboard Liste alphabetisch (mit status)
// -----------------------------------------------------------------------------
function getProjectsByOwnerAlphabetically(int $ownerId): array {
    global $pdo;

    try {
        $stmt = $pdo->prepare("
            SELECT
                p.id,
                p.name,
                p.api_key,
                p.api_secret,
                p.version,
                COALESCE(ps.hwid_enabled, 0) AS hwid_enabled,
                COALESCE(ps.status, 'active') AS status
            FROM projects p
            LEFT JOIN project_settings ps ON ps.project_id = p.id
            WHERE p.owner_id = :owner_id
            ORDER BY p.name ASC
        ");
        $stmt->execute([':owner_id' => $ownerId]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("DB Error [getProjectsByOwnerAlphabetically]: " . $e->getMessage());
        return [];
    }
}

function deleteProjectFully(int $projectId, int $ownerId): bool {
    global $pdo;

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("DELETE FROM activity_logs WHERE project_id = :pid");
        $stmt->execute([':pid' => $projectId]);

        $stmt = $pdo->prepare("DELETE FROM project_tokens WHERE project_id = :pid");
        $stmt->execute([':pid' => $projectId]);

        $stmt = $pdo->prepare("DELETE FROM project_users WHERE project_id = :pid");
        $stmt->execute([':pid' => $projectId]);

        $stmt = $pdo->prepare("DELETE FROM projects WHERE id = :id AND owner_id = :owner_id");
        $stmt->execute([
            ':id' => $projectId,
            ':owner_id' => $ownerId
        ]);

        $pdo->commit();
        return true;

    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("DB Error [deleteProjectFully]: " . $e->getMessage());
        return false;
    }
}

function getProjectByIdAndOwner(int $projectId, int $ownerId) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT *
            FROM projects
            WHERE id = :id AND owner_id = :owner_id
            LIMIT 1
        ");
        $stmt->execute([
            ':id' => $projectId,
            ':owner_id' => $ownerId
        ]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("DB Error [getProjectByIdAndOwner]: " . $e->getMessage());
        return false;
    }
}

function getProjectByIdAndOwnerWithSettings(int $projectId, int $ownerId) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT
                p.*,
                ps.download_link,
                ps.session_expiration,
                ps.killswitch_enabled,
                ps.hwid_enabled,
                ps.dev_mode,
                ps.status,

                ps.allow_register,
                ps.email_verification_required,
                ps.password_policy,
                ps.max_login_attempts,
                ps.captcha_enabled,
                ps.login_cooldown_seconds,
                ps.force_logout_on_password_change
            FROM projects p
            LEFT JOIN project_settings ps ON ps.project_id = p.id
            WHERE p.id = :id AND p.owner_id = :owner_id
            LIMIT 1
        ");
        $stmt->execute([
            ':id' => $projectId,
            ':owner_id' => $ownerId
        ]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("DB Error [getProjectByIdAndOwnerWithSettings]: " . $e->getMessage());
        return false;
    }
}

// -----------------------------------------------------------------------------
// API Helper: Status anhand API Key prüfen
// Rückgabe: ['status'=>'active|disabled|archived', 'project_id'=>int, ...] oder null
// -----------------------------------------------------------------------------
function getProjectByApiKeyWithSettings(string $apiKey): ?array {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT
                p.id,
                p.owner_id,
                p.name,
                p.api_key,
                p.api_secret,
                p.version,
                COALESCE(ps.status, 'active') AS status,
                ps.killswitch_enabled,
                ps.hwid_enabled
            FROM projects p
            LEFT JOIN project_settings ps ON ps.project_id = p.id
            WHERE p.api_key = :k
            LIMIT 1
        ");
        $stmt->execute([':k' => $apiKey]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (PDOException $e) {
        error_log("DB Error [getProjectByApiKeyWithSettings]: " . $e->getMessage());
        return null;
    }
}

/**
 * API erlauben?
 * - active    => true
 * - archived  => true
 * - disabled  => false
 */
function isProjectApiAllowed(string $apiKey): bool {
    $p = getProjectByApiKeyWithSettings($apiKey);
    if (!$p) return false;
    $status = strtolower((string)($p['status'] ?? 'active'));
    return ($status === 'active' || $status === 'archived');
}

?>
