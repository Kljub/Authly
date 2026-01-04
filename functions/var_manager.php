<?php
// ============================================================================
// AUTHLY – VAR MANAGER (DB + ENCRYPTION + API KEY ROTATION SUPPORT)
// PFAD: /functions/var_manager.php
// ============================================================================
//
// Speichert Project-Vars verschlüsselt in project_vars.value_enc
// Verschlüsselung: AES-256-GCM
// Key: abgeleitet aus projects.api_key + AUTHLY_KEY (Pepper aus config.php)
//
// Wichtig:
// - Wenn api_key geändert wird: rotateProjectVarsEncryption() bzw.
//   updateProjectApiKeyWithVarRotation() nutzen, damit alle Vars kompatibel
//   umverschlüsselt werden.
//
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
    die('❌ DB-Verbindung fehlgeschlagen: ' . htmlspecialchars((string)$e->getMessage()));
}

// -----------------------------------------------------------------------------
// Last error helper
// -----------------------------------------------------------------------------
if (!function_exists('authly_var_last_error')) {
    function authly_var_last_error(?string $set = null): string {
        static $err = '';
        if ($set !== null) $err = $set;
        return $err;
    }
}

// -----------------------------------------------------------------------------
// Key derivation: 32 bytes
// -----------------------------------------------------------------------------
if (!function_exists('authly_derive_project_key')) {
    function authly_derive_project_key(string $apiKey): string {
        // 32 bytes binary key
        return hash('sha256', (defined('AUTHLY_KEY') ? AUTHLY_KEY : '') . '|' . $apiKey, true);
    }
}

// -----------------------------------------------------------------------------
// Encrypt/Decrypt: AES-256-GCM
// Format: base64( IV(12) || TAG(16) || CIPHERTEXT )
// -----------------------------------------------------------------------------
if (!function_exists('authly_encrypt_var_value')) {
    function authly_encrypt_var_value(string $plain, string $apiKey): string {
        $key = authly_derive_project_key($apiKey);
        $iv  = random_bytes(12);
        $tag = '';

        $cipher = openssl_encrypt(
            $plain,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );

        if ($cipher === false || $tag === '') {
            throw new RuntimeException('Encryption failed');
        }

        return base64_encode($iv . $tag . $cipher);
    }
}

if (!function_exists('authly_decrypt_var_value')) {
    function authly_decrypt_var_value(string $encB64, string $apiKey): string {
        $raw = base64_decode($encB64, true);
        if ($raw === false || strlen($raw) < 29) {
            throw new RuntimeException('Invalid encrypted payload');
        }

        $iv     = substr($raw, 0, 12);
        $tag    = substr($raw, 12, 16);
        $cipher = substr($raw, 28);

        $key = authly_derive_project_key($apiKey);

        $plain = openssl_decrypt(
            $cipher,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plain === false) {
            throw new RuntimeException('Decryption failed');
        }

        return $plain;
    }
}

// -----------------------------------------------------------------------------
// Project api_key getter
// -----------------------------------------------------------------------------
if (!function_exists('getProjectApiKey')) {
    function getProjectApiKey(int $projectId): ?string {
        global $pdo;

        $st = $pdo->prepare("SELECT api_key FROM projects WHERE id = :pid LIMIT 1");
        $st->execute([':pid' => $projectId]);
        $k = $st->fetchColumn();

        if ($k === false) return null;
        $k = (string)$k;
        return $k !== '' ? $k : null;
    }
}

// -----------------------------------------------------------------------------
// Vars CRUD
// -----------------------------------------------------------------------------
if (!function_exists('getProjectVars')) {
    function getProjectVars(int $projectId): array {
        global $pdo;

        $st = $pdo->prepare("
            SELECT id, project_id, name, value_enc, created_at, updated_at
            FROM project_vars
            WHERE project_id = :pid
            ORDER BY id DESC
        ");
        $st->execute([':pid' => $projectId]);

        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('getProjectVar')) {
    function getProjectVar(int $projectId, int $varId): ?array {
        global $pdo;

        $st = $pdo->prepare("
            SELECT id, project_id, name, value_enc, created_at, updated_at
            FROM project_vars
            WHERE id = :id AND project_id = :pid
            LIMIT 1
        ");
        $st->execute([':id' => $varId, ':pid' => $projectId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }
}

if (!function_exists('createProjectVar')) {
    function createProjectVar(int $projectId, string $name, string $valuePlain): bool {
        global $pdo;
        authly_var_last_error('');

        $name = trim($name);
        if ($name === '' || trim($valuePlain) === '') {
            authly_var_last_error('Bitte Name und Value angeben.');
            return false;
        }

        $apiKey = getProjectApiKey($projectId);
        if ($apiKey === null) {
            authly_var_last_error('Project api_key fehlt.');
            return false;
        }

        try {
            $enc = authly_encrypt_var_value($valuePlain, $apiKey);

            $st = $pdo->prepare("
                INSERT INTO project_vars (project_id, name, value_enc)
                VALUES (:pid, :name, :enc)
            ");
            return $st->execute([
                ':pid'  => $projectId,
                ':name' => $name,
                ':enc'  => $enc
            ]);
        } catch (Throwable $e) {
            authly_var_last_error('Fehler beim Erstellen der Var.');
            error_log('VAR create error: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('updateProjectVar')) {
    function updateProjectVar(int $projectId, int $varId, string $newValuePlain): bool {
        global $pdo;
        authly_var_last_error('');

        if ($varId <= 0 || trim($newValuePlain) === '') {
            authly_var_last_error('Bitte Value angeben.');
            return false;
        }

        $apiKey = getProjectApiKey($projectId);
        if ($apiKey === null) {
            authly_var_last_error('Project api_key fehlt.');
            return false;
        }

        try {
            $enc = authly_encrypt_var_value($newValuePlain, $apiKey);

            $st = $pdo->prepare("
                UPDATE project_vars
                SET value_enc = :enc, updated_at = CURRENT_TIMESTAMP
                WHERE id = :id AND project_id = :pid
            ");
            return $st->execute([
                ':enc' => $enc,
                ':id'  => $varId,
                ':pid' => $projectId
            ]);
        } catch (Throwable $e) {
            authly_var_last_error('Fehler beim Speichern der Var.');
            error_log('VAR update error: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('deleteProjectVar')) {
    function deleteProjectVar(int $projectId, int $varId): bool {
        global $pdo;

        $st = $pdo->prepare("DELETE FROM project_vars WHERE id = :id AND project_id = :pid");
        return $st->execute([':id' => $varId, ':pid' => $projectId]);
    }
}

// -----------------------------------------------------------------------------
// UI helper: decrypt
// -----------------------------------------------------------------------------
if (!function_exists('decryptVarForProject')) {
    function decryptVarForProject(int $projectId, string $valueEnc): string {
        $apiKey = getProjectApiKey($projectId);
        if ($apiKey === null) return '';

        try {
            return authly_decrypt_var_value($valueEnc, $apiKey);
        } catch (Throwable $e) {
            return '';
        }
    }
}

// -----------------------------------------------------------------------------
// Rotation support (old api_key -> new api_key)
// -----------------------------------------------------------------------------
if (!function_exists('rotateProjectVarsEncryption')) {
    function rotateProjectVarsEncryption(int $projectId, string $oldApiKey, string $newApiKey): int {
        global $pdo;
        authly_var_last_error('');

        $oldApiKey = trim($oldApiKey);
        $newApiKey = trim($newApiKey);

        if ($oldApiKey === '' || $newApiKey === '') {
            authly_var_last_error('API Keys fehlen.');
            return 0;
        }

        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare("SELECT id, value_enc FROM project_vars WHERE project_id = :pid");
            $st->execute([':pid' => $projectId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);

            $up = $pdo->prepare("
                UPDATE project_vars
                SET value_enc = :enc, updated_at = CURRENT_TIMESTAMP
                WHERE id = :id AND project_id = :pid
            ");

            $count = 0;
            foreach ($rows as $r) {
                $id  = (int)$r['id'];
                $enc = (string)$r['value_enc'];

                $plain  = authly_decrypt_var_value($enc, $oldApiKey);
                $newEnc = authly_encrypt_var_value($plain, $newApiKey);

                $up->execute([':enc' => $newEnc, ':id' => $id, ':pid' => $projectId]);
                $count++;
            }

            $pdo->commit();
            return $count;
        } catch (Throwable $e) {
            $pdo->rollBack();
            authly_var_last_error('Rotation fehlgeschlagen.');
            error_log('VAR rotate error: ' . $e->getMessage());
            return 0;
        }
    }
}

// Optional: update projects.api_key + rotate in one go
if (!function_exists('updateProjectApiKeyWithVarRotation')) {
    function updateProjectApiKeyWithVarRotation(int $projectId, int $ownerId, string $newApiKey): bool {
        global $pdo;
        authly_var_last_error('');

        $newApiKey = trim($newApiKey);
        if ($newApiKey === '') {
            authly_var_last_error('Neuer API Key fehlt.');
            return false;
        }

        $st = $pdo->prepare("SELECT api_key FROM projects WHERE id = :pid AND owner_id = :oid LIMIT 1");
        $st->execute([':pid' => $projectId, ':oid' => $ownerId]);
        $oldApiKey = $st->fetchColumn();

        if ($oldApiKey === false) {
            authly_var_last_error('Projekt nicht gefunden.');
            return false;
        }

        $oldApiKey = (string)$oldApiKey;
        if ($oldApiKey === $newApiKey) {
            return true;
        }

        $pdo->beginTransaction();
        try {
            // rotate vars
            $st2 = $pdo->prepare("SELECT id, value_enc FROM project_vars WHERE project_id = :pid");
            $st2->execute([':pid' => $projectId]);
            $rows = $st2->fetchAll(PDO::FETCH_ASSOC);

            $upVar = $pdo->prepare("
                UPDATE project_vars
                SET value_enc = :enc, updated_at = CURRENT_TIMESTAMP
                WHERE id = :id AND project_id = :pid
            ");

            foreach ($rows as $r) {
                $id  = (int)$r['id'];
                $enc = (string)$r['value_enc'];

                $plain  = authly_decrypt_var_value($enc, $oldApiKey);
                $newEnc = authly_encrypt_var_value($plain, $newApiKey);

                $upVar->execute([':enc' => $newEnc, ':id' => $id, ':pid' => $projectId]);
            }

            // update api_key
            $upP = $pdo->prepare("
                UPDATE projects
                SET api_key = :k, updated_at = CURRENT_TIMESTAMP
                WHERE id = :pid AND owner_id = :oid
            ");
            $upP->execute([':k' => $newApiKey, ':pid' => $projectId, ':oid' => $ownerId]);

            $pdo->commit();
            return true;

        } catch (Throwable $e) {
            $pdo->rollBack();
            authly_var_last_error('API Key Update/Rotation fehlgeschlagen.');
            error_log('APIKEY rotate+update error: ' . $e->getMessage());
            return false;
        }
    }
}
