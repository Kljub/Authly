<?php
// ============================================================================
// AUTHLY – PROJECT-BASED DATABASE FUNCTIONS
// PFAD: /functions/db_project_functions.php
// ============================================================================

require_once __DIR__ . '/../db/config.php';

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die('❌ DB-Verbindung fehlgeschlagen: ' . htmlspecialchars($e->getMessage()));
}

// ============================================================================
// PROJECT USERS
// ============================================================================

// Alle User eines Projekts holen
function getProjectUsers(int $projectId): array {
    global $pdo;

    $stmt = $pdo->prepare("SELECT * FROM project_users WHERE project_id = :pid ORDER BY id DESC");
    $stmt->execute([':pid' => $projectId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Einzelnen Project-User holen
function getProjectUser(int $userId, int $projectId) {
    global $pdo;

    $stmt = $pdo->prepare("
        SELECT *
        FROM project_users
        WHERE id = :uid AND project_id = :pid
        LIMIT 1
    ");
    $stmt->execute([
        ':uid' => $userId,
        ':pid' => $projectId
    ]);

    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Project-User erstellen
function createProjectUser(
    int $projectId,
    string $username,
    ?string $email = null,
    ?string $password = null,
    ?string $token = null,
    int $rank = 0
): bool {

    global $pdo;

    $sql = "
        INSERT INTO project_users
            (`project_id`, `username`, `email`, `password`, `UserVar`, `hwid`, `banned`, `expires_at`, `ip`, `Rank_id`)
        VALUES
            (:pid, :username, :email, :password, NULL, NULL, 0, NULL, NULL, :rank)
    ";

    $stmt = $pdo->prepare($sql);

    return $stmt->execute([
        ':pid'      => $projectId,
        ':username' => $username,
        ':email'    => $email,
        ':password' => $password,
        ':rank'     => $rank
    ]);
}

// HWID zurücksetzen
function resetProjectUserHWID(int $userId, int $projectId): bool {
    global $pdo;

    $stmt = $pdo->prepare("
        UPDATE project_users
        SET hwid = NULL
        WHERE id = :uid AND project_id = :pid
    ");

    return $stmt->execute([
        ':uid' => $userId,
        ':pid' => $projectId
    ]);
}

// User bannen
function banProjectUser(int $userId, int $projectId): bool {
    global $pdo;

    $stmt = $pdo->prepare("
        UPDATE project_users
        SET banned = 1
        WHERE id = :uid AND project_id = :pid
    ");

    return $stmt->execute([
        ':uid' => $userId,
        ':pid' => $projectId
    ]);
}

// User entbannen
function unbanProjectUser(int $userId, int $projectId): bool {
    global $pdo;

    $stmt = $pdo->prepare("
        UPDATE project_users
        SET banned = 0
        WHERE id = :uid AND project_id = :pid
    ");

    return $stmt->execute([
        ':uid' => $userId,
        ':pid' => $projectId
    ]);
}

// Benutzer löschen
function deleteProjectUser(int $userId, int $projectId): bool {
    global $pdo;

    $stmt = $pdo->prepare("
        DELETE FROM project_users
        WHERE id = :uid AND project_id = :pid
    ");

    return $stmt->execute([
        ':uid' => $userId,
        ':pid' => $projectId
    ]);
}

// Ablaufdatum setzen
function updateProjectUserExpire(int $userId, int $projectId, string $newExpire): bool {
    global $pdo;

    $stmt = $pdo->prepare("
        UPDATE project_users
        SET expires_at = :exp
        WHERE id = :uid AND project_id = :pid
    ");

    return $stmt->execute([
        ':exp' => $newExpire,
        ':uid' => $userId,
        ':pid' => $projectId
    ]);
}

// UserVar setzen
function updateProjectUserVar(int $userId, int $projectId, string $var): bool {
    global $pdo;

    $stmt = $pdo->prepare("
        UPDATE project_users
        SET UserVar = :uv
        WHERE id = :uid AND project_id = :pid
    ");

    return $stmt->execute([
        ':uv'  => $var,
        ':uid' => $userId,
        ':pid' => $projectId
    ]);
}

// ============================================================================
// ERGÄNZUNG für Dropdown-Editing (Multi-Field Update)
// ============================================================================

/**
 * Update beliebige Felder eines Project-Users in EINEM Query.
 * Erlaubte Keys: username,email,password,UserVar,hwid,banned,expires_at,ip,Rank_id
 */
function updateProjectUser(int $userId, int $projectId, array $fields): bool {
    global $pdo;
    if (empty($fields)) return false;

    $allowed = ['username','email','password','UserVar','hwid','banned','expires_at','ip','Rank_id'];
    $set = [];
    $params = [':uid' => $userId, ':pid' => $projectId];

    foreach ($fields as $k => $v) {
        if (!in_array($k, $allowed, true)) continue;
        $set[] = "`$k` = :$k";
        $params[":$k"] = $v;
    }

    if (empty($set)) return false;

    $sql = "UPDATE project_users SET " . implode(', ', $set) . " WHERE id = :uid AND project_id = :pid";
    $stmt = $pdo->prepare($sql);
    return $stmt->execute($params);
}

/**
 * Komfort: Ban togglen (0/1) und neuen Status zurückgeben.
 */
function toggleProjectUserBan(int $userId, int $projectId): ?int {
    $u = getProjectUser($userId, $projectId);
    if (!$u) return null;

    $new = ((int)($u['banned'] ?? 0) === 1) ? 0 : 1;
    $ok = updateProjectUser($userId, $projectId, ['banned' => $new]);

    return $ok ? $new : null;
}

/**
 * Komfort: Passwort separat setzen (hashed sollte bereits übergeben werden!)
 */
function updateProjectUserPassword(int $userId, int $projectId, string $hashedPassword): bool {
    return updateProjectUser($userId, $projectId, ['password' => $hashedPassword]);
}

/**
 * Komfort: Core-Felder (z.B. fürs Dropdown)
 */
function updateProjectUserCore(
    int $userId,
    int $projectId,
    string $username,
    ?string $email,
    ?string $hwid,
    ?string $ip,
    ?int $rankId
): bool {
    $fields = [
        'username' => $username,
        'email'    => $email,
        'hwid'     => $hwid,
        'ip'       => $ip
    ];
    if ($rankId !== null) $fields['Rank_id'] = $rankId;

    return updateProjectUser($userId, $projectId, $fields);
}
