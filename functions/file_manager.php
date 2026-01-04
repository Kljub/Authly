<?php
// ============================================================================
// AUTHLY – PROJECT FILE MANAGER (Encrypted Storage)
// PFAD: /functions/file_manager.php
// ============================================================================

require_once __DIR__ . '/../db/config.php';
require_once __DIR__ . '/dbfunctions.php';

// -----------------------------------------------------------------------------
// Error handling
// -----------------------------------------------------------------------------
$GLOBALS['AUTHLY_FILE_LAST_ERROR'] = '';

function authly_file_last_error(): string {
    return (string)($GLOBALS['AUTHLY_FILE_LAST_ERROR'] ?? '');
}
function authly_file_set_error(string $msg): void {
    $GLOBALS['AUTHLY_FILE_LAST_ERROR'] = $msg;
}

// -----------------------------------------------------------------------------
// REQUIRE AUTHLY_KEY (server pepper)
// -----------------------------------------------------------------------------
if (!defined('AUTHLY_KEY')) {
    // Falls du AUTHLY_KEY in config.php nicht hast -> bitte dort definieren!
    // define('AUTHLY_KEY', '...random-long-secret...');
    define('AUTHLY_KEY', '');
}

// -----------------------------------------------------------------------------
// Helpers: Load project api_key
// -----------------------------------------------------------------------------
function authly_get_project_api_key(int $projectId): ?string {
    try {
        global $pdo;
        $st = $pdo->prepare("SELECT api_key FROM projects WHERE id = :id LIMIT 1");
        $st->execute([':id' => $projectId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        return (string)$row['api_key'];
    } catch (Throwable $e) {
        return null;
    }
}

// -----------------------------------------------------------------------------
// Crypto: AES-256-GCM, output base64(IV|TAG|CIPHERTEXT)
// -----------------------------------------------------------------------------
function authly_file_derive_key(string $apiKey): string {
    // 32 bytes key
    $pepper = (string)AUTHLY_KEY;
    return hash('sha256', $pepper . '|' . $apiKey, true);
}

function authly_encrypt_file_bytes(string $plainBytes, string $apiKey): string {
    $key = authly_file_derive_key($apiKey);
    $iv  = random_bytes(12);
    $tag = '';

    $cipher = openssl_encrypt(
        $plainBytes,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        '',
        16
    );

    if ($cipher === false || $tag === '') {
        throw new RuntimeException('Encryption failed.');
    }

    return base64_encode($iv . $tag . $cipher);
}

function authly_decrypt_file_bytes(string $encB64, string $apiKey): string {
    $bin = base64_decode($encB64, true);
    if ($bin === false || strlen($bin) < (12 + 16 + 1)) {
        throw new RuntimeException('Invalid encrypted payload.');
    }

    $iv    = substr($bin, 0, 12);
    $tag   = substr($bin, 12, 16);
    $cipher= substr($bin, 28);

    $key = authly_file_derive_key($apiKey);

    $plain = openssl_decrypt(
        $cipher,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        ''
    );

    if ($plain === false) {
        throw new RuntimeException('Decryption failed.');
    }
    return $plain;
}

// -----------------------------------------------------------------------------
// CRUD
// -----------------------------------------------------------------------------
function getProjectFiles(int $projectId): array {
    global $pdo;

    $st = $pdo->prepare("
        SELECT id, project_id, file_name, original_name, mime_type, file_size, content_enc, sha256_plain, created_at
        FROM project_files
        WHERE project_id = :pid
        ORDER BY id DESC
    ");
    $st->execute([':pid' => $projectId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function getProjectFile(int $projectId, int $fileId): ?array {
    global $pdo;

    $st = $pdo->prepare("
        SELECT id, project_id, file_name, original_name, mime_type, file_size, content_enc, sha256_plain, created_at
        FROM project_files
        WHERE project_id = :pid AND id = :id
        LIMIT 1
    ");
    $st->execute([':pid' => $projectId, ':id' => $fileId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function createProjectFileFromUpload(int $projectId, string $fileNameKey, array $upload): bool {
    authly_file_set_error('');

    try {
        if (!isset($upload['tmp_name']) || !is_uploaded_file($upload['tmp_name'])) {
            authly_file_set_error('Kein gültiger Upload.');
            return false;
        }

        $apiKey = authly_get_project_api_key($projectId);
        if (!$apiKey) {
            authly_file_set_error('Projekt-API-Key nicht gefunden.');
            return false;
        }

        $origName = (string)($upload['name'] ?? '');
        $mime     = (string)($upload['type'] ?? 'application/octet-stream');
        $size     = (int)($upload['size'] ?? 0);

        $bytes = file_get_contents($upload['tmp_name']);
        if ($bytes === false) {
            authly_file_set_error('Datei konnte nicht gelesen werden.');
            return false;
        }

        $sha = hash('sha256', $bytes);

        $enc = authly_encrypt_file_bytes($bytes, $apiKey);

        global $pdo;
        $st = $pdo->prepare("
            INSERT INTO project_files
                (project_id, file_name, original_name, mime_type, file_size, content_enc, sha256_plain)
            VALUES
                (:pid, :fn, :on, :mt, :fs, :enc, :sha)
        ");

        return $st->execute([
            ':pid' => $projectId,
            ':fn'  => $fileNameKey,
            ':on'  => $origName !== '' ? $origName : null,
            ':mt'  => $mime !== '' ? $mime : 'application/octet-stream',
            ':fs'  => $size,
            ':enc' => $enc,
            ':sha' => $sha
        ]);
    } catch (Throwable $e) {
        authly_file_set_error('Upload/Encrypt fehlgeschlagen.');
        return false;
    }
}

function deleteProjectFile(int $projectId, int $fileId): bool {
    global $pdo;

    $st = $pdo->prepare("DELETE FROM project_files WHERE project_id = :pid AND id = :id");
    return $st->execute([':pid' => $projectId, ':id' => $fileId]);
}

function decryptProjectFileContent(int $projectId, string $contentEnc): ?string {
    authly_file_set_error('');

    try {
        $apiKey = authly_get_project_api_key($projectId);
        if (!$apiKey) {
            authly_file_set_error('Projekt-API-Key nicht gefunden.');
            return null;
        }
        return authly_decrypt_file_bytes($contentEnc, $apiKey);
    } catch (Throwable $e) {
        authly_file_set_error('Decrypt fehlgeschlagen.');
        return null;
    }
}
