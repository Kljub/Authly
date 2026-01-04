<?php
// ============================================================================
// AUTHLY – TOKEN MANAGER (MySQL Commands zentral)
// PFAD: /functions/token_manager.php
// UPDATE: Bulk Delete (mehrere Tokens auf einmal löschen) + Custom Mask { ... } Random
// ============================================================================

require_once __DIR__ . '/../db/config.php';

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (PDOException $e) {
    die('❌ DB-Verbindung fehlgeschlagen: ' . htmlspecialchars($e->getMessage()));
}

// ============================================================================
// LAST ERROR
// ============================================================================

$GLOBALS['AUTHLY_TOKEN_LAST_ERROR'] = '';

function authly_token_set_last_error(string $msg): void {
    $GLOBALS['AUTHLY_TOKEN_LAST_ERROR'] = $msg;
}
function authly_token_last_error(): string {
    return (string)($GLOBALS['AUTHLY_TOKEN_LAST_ERROR'] ?? '');
}

// ============================================================================
// PRESETS / MASK
// ============================================================================

function authlyTokenPresets(): array {
    return [
        'normal'     => 'XXXX-XXXX-XXXX-XXXX',
        'big_normal' => 'XXXXX-XXXXX-XXXXX-XXXXX-XXXXX-XXXXX',
        'guid'       => 'XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX',
        'custom'     => '{XXXX-XXXX-XXXX-XXXX}',
    ];
}

function authlyTokenMaskFromType(string $type, ?string $customMask = null): string {
    $presets = authlyTokenPresets();
    $type = strtolower(trim($type));

    if ($type === 'custom') {
        $m = trim((string)$customMask);
        return $m !== '' ? $m : $presets['custom'];
    }

    return $presets[$type] ?? $presets['normal'];
}

/**
 * Generator:
 * - Presets: X überall random
 * - Custom: NUR X innerhalb {...} random, außerhalb bleibt alles literal (inkl "X")
 */
function authlyGenTokenFromMask(string $mask, bool $customScoped = false): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $mask = (string)$mask;

    if (!$customScoped) {
        $out = '';
        $hasX = false;
        $len = strlen($mask);
        for ($i = 0; $i < $len; $i++) {
            $c = $mask[$i];
            if ($c === 'X') {
                $hasX = true;
                $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            } else {
                $out .= $c;
            }
        }
        return $hasX ? $out : '';
    }

    $out = '';
    $in = false;
    $hasAnyRandom = false;

    $len = strlen($mask);
    for ($i = 0; $i < $len; $i++) {
        $c = $mask[$i];

        if ($c === '{') { $in = true; continue; }
        if ($c === '}') { $in = false; continue; }

        if ($in && $c === 'X') {
            $hasAnyRandom = true;
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        } else {
            $out .= $c;
        }
    }

    return $hasAnyRandom ? $out : '';
}

// ============================================================================
// SQL HELPERS / COLUMN DISCOVERY
// ============================================================================

function authly_sql_ident(string $name): string {
    $name = str_replace('`', '``', $name);
    return "`{$name}`";
}

function authly_table_columns(string $table): array {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];

    global $pdo;
    $cols = [];

    try {
        $st = $pdo->prepare("
            SELECT COLUMN_NAME
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :t
        ");
        $st->execute([':t' => $table]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $cols[strtolower((string)$r['COLUMN_NAME'])] = (string)$r['COLUMN_NAME'];
        }
    } catch (Throwable $e) {
        $cols = [];
    }

    $cache[$table] = $cols;
    return $cols;
}

function authly_find_column(string $table, array $candidates): ?string {
    $cols = authly_table_columns($table);
    foreach ($candidates as $c) {
        $k = strtolower((string)$c);
        if (isset($cols[$k])) return $cols[$k];
    }
    return null;
}

function authly_col_project_id(): ?string { return authly_find_column('project_tokens', ['project_id', 'projectid', 'pid']); }
function authly_col_token(): ?string     { return authly_find_column('project_tokens', ['token', 'token_key', 'tokenvalue', 'code']); }
function authly_col_days(): ?string      { return authly_find_column('project_tokens', ['days', 'day', 'duration', 'expire_days']); }
function authly_col_used(): ?string      { return authly_find_column('project_tokens', ['used', 'is_used', 'isused']); }
function authly_col_used_by(): ?string   { return authly_find_column('project_tokens', ['used_by', 'usedby', 'used_user', 'usedusername']); }
function authly_col_rank_id(): ?string   { return authly_find_column('project_tokens', ['rank_id', 'Rank_id', 'rankid']); }

// ============================================================================
// EXISTS / READ
// ============================================================================

function authlyTokenExists(int $projectId, string $token): bool {
    global $pdo;

    $pidCol = authly_col_project_id();
    $tokCol = authly_col_token();
    if ($pidCol === null || $tokCol === null) return false;

    $sql = "SELECT id FROM project_tokens WHERE " . authly_sql_ident($pidCol) . " = :pid AND " . authly_sql_ident($tokCol) . " = :t LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([':pid' => $projectId, ':t' => $token]);
    return (bool)$st->fetchColumn();
}

function getProjectTokens(int $projectId, string $filter = 'all'): array {
    global $pdo;

    $pidCol  = authly_col_project_id();
    $usedCol = authly_col_used();
    if ($pidCol === null) return [];

    $filter = strtolower(trim($filter));
    $whereUsed = '';

    if ($usedCol !== null) {
        if ($filter === 'used')   $whereUsed = " AND " . authly_sql_ident($usedCol) . " = 1";
        if ($filter === 'unused') $whereUsed = " AND " . authly_sql_ident($usedCol) . " = 0";
    }

    $sql = "SELECT * FROM project_tokens WHERE " . authly_sql_ident($pidCol) . " = :pid {$whereUsed} ORDER BY id DESC";
    $st = $pdo->prepare($sql);
    $st->execute([':pid' => $projectId]);
    return $st->fetchAll();
}

// ============================================================================
// CREATE
// ============================================================================

function createProjectTokens(
    int $projectId,
    int $amount,
    int $days,
    int $rankId,
    string $mask
): int {
    global $pdo;

    authly_token_set_last_error('');

    if ($amount <= 0) { authly_token_set_last_error('Amount <= 0'); return 0; }
    if ($amount > 500) $amount = 500;

    $mask = trim($mask);
    if ($mask === '') { authly_token_set_last_error("Ungültige Mask (leer)."); return 0; }

    $isScopedCustom = (strpos($mask, '{') !== false || strpos($mask, '}') !== false);

    if ($isScopedCustom) {
        if (!preg_match('/\{[^}]*X[^}]*\}/', $mask)) {
            authly_token_set_last_error("Custom Mask braucht mindestens ein X innerhalb {...}.");
            return 0;
        }
    } else {
        if (strpos($mask, 'X') === false) {
            authly_token_set_last_error("Mask muss mindestens ein 'X' enthalten.");
            return 0;
        }
    }

    $pidCol    = authly_col_project_id();
    $tokCol    = authly_col_token();
    $daysCol   = authly_col_days();
    $usedCol   = authly_col_used();
    $rankCol   = authly_col_rank_id();
    $usedByCol = authly_col_used_by();

    if ($pidCol === null || $tokCol === null) {
        authly_token_set_last_error("DB Schema Problem: project_tokens braucht project_id + token");
        return 0;
    }

    $cols = [];
    $vals = [];
    $paramsBase = [];

    $cols[] = authly_sql_ident($pidCol); $vals[] = ':pid';  $paramsBase[':pid'] = $projectId;
    $cols[] = authly_sql_ident($tokCol); $vals[] = ':tok';

    if ($daysCol !== null)  { $cols[] = authly_sql_ident($daysCol);  $vals[] = ':days';     $paramsBase[':days'] = $days; }
    if ($rankCol !== null)  { $cols[] = authly_sql_ident($rankCol);  $vals[] = ':rank_id';  $paramsBase[':rank_id'] = $rankId; }
    if ($usedCol !== null)  { $cols[] = authly_sql_ident($usedCol);  $vals[] = '0'; }
    if ($usedByCol !== null){ $cols[] = authly_sql_ident($usedByCol);$vals[] = 'NULL'; }

    $sql = "INSERT INTO project_tokens (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ")";

    $pdo->beginTransaction();

    try {
        $stIns = $pdo->prepare($sql);

        $created = 0;
        $tries = 0;
        $maxTries = $amount * 40;

        while ($created < $amount && $tries < $maxTries) {
            $tries++;

            $token = authlyGenTokenFromMask($mask, $isScopedCustom);
            if ($token === '') continue;

            if (authlyTokenExists($projectId, $token)) continue;

            $params = $paramsBase;
            $params[':tok'] = $token;

            $stIns->execute($params);
            $created++;
        }

        $pdo->commit();

        if ($created === 0) authly_token_set_last_error("0 erstellt (tries={$tries}).");
        return $created;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        authly_token_set_last_error("SQL ERROR: " . $e->getMessage() . " | SQL=" . $sql);
        return 0;
    }
}

// ============================================================================
// DELETE / PURGE
// ============================================================================

function deleteProjectToken(int $projectId, int $tokenId): bool {
    global $pdo;

    $pidCol = authly_col_project_id();
    if ($pidCol === null) return false;

    $sql = "DELETE FROM project_tokens WHERE id = :id AND " . authly_sql_ident($pidCol) . " = :pid";
    $st = $pdo->prepare($sql);
    return $st->execute([':id' => $tokenId, ':pid' => $projectId]);
}

/**
 * Bulk delete (IDs sind NICHT sichtbar im UI, aber werden als hidden/checkbox gesendet)
 * Returns: Anzahl gelöschter Rows
 */
function deleteProjectTokensBulk(int $projectId, array $tokenIds): int {
    global $pdo;

    $pidCol = authly_col_project_id();
    if ($pidCol === null) return 0;

    $ids = [];
    foreach ($tokenIds as $v) {
        $i = (int)$v;
        if ($i > 0) $ids[$i] = $i; // unique
    }
    $ids = array_values($ids);
    if (count($ids) === 0) return 0;

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = "DELETE FROM project_tokens
            WHERE " . authly_sql_ident($pidCol) . " = ?
              AND id IN ($placeholders)";

    $params = array_merge([$projectId], $ids);

    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return (int)$st->rowCount();
    } catch (Throwable $e) {
        authly_token_set_last_error("SQL ERROR (bulk delete): " . $e->getMessage());
        return 0;
    }
}

function purgeAllProjectTokens(int $projectId): bool {
    global $pdo;

    $pidCol = authly_col_project_id();
    if ($pidCol === null) return false;

    $sql = "DELETE FROM project_tokens WHERE " . authly_sql_ident($pidCol) . " = :pid";
    $st = $pdo->prepare($sql);
    return $st->execute([':pid' => $projectId]);
}

function purgeUsedProjectTokens(int $projectId): bool {
    global $pdo;

    $pidCol  = authly_col_project_id();
    $usedCol = authly_col_used();
    if ($pidCol === null || $usedCol === null) return false;

    $sql = "DELETE FROM project_tokens WHERE " . authly_sql_ident($pidCol) . " = :pid AND " . authly_sql_ident($usedCol) . " = 1";
    $st = $pdo->prepare($sql);
    return $st->execute([':pid' => $projectId]);
}

function purgeUnusedProjectTokens(int $projectId): bool {
    global $pdo;

    $pidCol  = authly_col_project_id();
    $usedCol = authly_col_used();
    if ($pidCol === null || $usedCol === null) return false;

    $sql = "DELETE FROM project_tokens WHERE " . authly_sql_ident($pidCol) . " = :pid AND " . authly_sql_ident($usedCol) . " = 0";
    $st = $pdo->prepare($sql);
    return $st->execute([':pid' => $projectId]);
}

// ============================================================================
// TOGGLE USED / UNUSED
// ============================================================================

function toggleProjectTokenUsed(int $projectId, int $tokenId): bool {
    global $pdo;

    $pidCol  = authly_col_project_id();
    $usedCol = authly_col_used();
    $usedBy  = authly_col_used_by();

    if ($pidCol === null || $usedCol === null) return false;

    // aktuellen Status holen
    $st = $pdo->prepare("
        SELECT {$usedCol}
        FROM project_tokens
        WHERE id = :id AND {$pidCol} = :pid
        LIMIT 1
    ");
    $st->execute([':id' => $tokenId, ':pid' => $projectId]);
    $row = $st->fetch();

    if (!$row) return false;

    $newUsed = ((int)$row[$usedCol] === 1) ? 0 : 1;

    $sql = "
        UPDATE project_tokens
        SET {$usedCol} = :used
    ";

    // used_by zurücksetzen wenn UNUSED
    if ($usedBy !== null && $newUsed === 0) {
        $sql .= ", {$usedBy} = NULL";
    }

    $sql .= " WHERE id = :id AND {$pidCol} = :pid";

    $up = $pdo->prepare($sql);
    return $up->execute([
        ':used' => $newUsed,
        ':id'   => $tokenId,
        ':pid'  => $projectId
    ]);
}
