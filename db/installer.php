<?php
// ============================================================================
// Authly - Datenbank Installer (Updated)
// ============================================================================
// Erstellt alle benötigten Tabellen – angepasst an deine neue DB-Struktur
// ============================================================================

function authly_install_tables(PDO $pdo) {
    $tables = [];

    // USERS -------------------------------------------------------------
    $tables[] = "
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            uuid VARCHAR(64) NOT NULL,
            username VARCHAR(64) NOT NULL,
            email VARCHAR(128) NOT NULL,
            password VARCHAR(255) NOT NULL,

            -- Empfehlung: Default = 2 (user). Wenn du bewusst Admin als Default willst -> wieder auf 1 setzen.
            role_id INT DEFAULT 2,

            status ENUM('active','banned','inactive') DEFAULT 'active',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_login DATETIME NULL,
            ip_address VARCHAR(45) DEFAULT NULL,

            UNIQUE KEY uq_users_email (email),
            UNIQUE KEY uq_users_uuid (uuid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";

    // ROLES -------------------------------------------------------------
    $tables[] = "
        CREATE TABLE IF NOT EXISTS roles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(64) NOT NULL,
            slug VARCHAR(64) NOT NULL UNIQUE,
            project_limit INT DEFAULT 3,
            dev_mode_allowed BOOLEAN DEFAULT 0,
            killswitch_allowed BOOLEAN DEFAULT 0,
            hwid_toggle_allowed BOOLEAN DEFAULT 0,
            custom_api_allowed BOOLEAN DEFAULT 0,
            can_manage_users BOOLEAN DEFAULT 0,
            can_manage_roles BOOLEAN DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";

    // PROJECTS ----------------------------------------------------------
    $tables[] = "
        CREATE TABLE projects (
  id int NOT NULL,
  owner_id int NOT NULL,
  name varchar(128) NOT NULL,
  api_key varchar(128) NOT NULL,
  api_secret varchar(128) NOT NULL,
  version varchar(16) DEFAULT '1.0',
  created_at datetime DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

    ";

    // PROJECT USERS -----------------------------------------------------
    $tables[] = "
        CREATE TABLE IF NOT EXISTS project_users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            username VARCHAR(128) NOT NULL,
            email VARCHAR(128) DEFAULT NULL,
            password VARCHAR(255) DEFAULT NULL,

            UserVar TEXT DEFAULT NULL,

            subscription_paused TINYINT(1) NOT NULL DEFAULT 0,

            hwid VARCHAR(255) DEFAULT NULL,
            banned TINYINT(1) DEFAULT 0,
            expires_at DATETIME DEFAULT NULL,
            ip VARCHAR(45) DEFAULT NULL,
            Rank_id INT NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";

    // PROJECT TOKENS ----------------------------------------------------
    $tables[] = "
        CREATE TABLE IF NOT EXISTS project_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            token VARCHAR(255) NOT NULL,
            days INT DEFAULT 0,
            rank_id INT DEFAULT 0,
            used TINYINT(1) DEFAULT 0,
            used_by VARCHAR(128) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";

    // PROJECT VARS (ENCRYPTED) -----------------------------------------
    $tables[] = "
        CREATE TABLE IF NOT EXISTS project_vars (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            name VARCHAR(190) NOT NULL,
            value_enc MEDIUMTEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_project_var (project_id, name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";

    // ACTIVITY LOGS -----------------------------------------------------
    $tables[] = "
        CREATE TABLE IF NOT EXISTS activity_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT DEFAULT NULL,
            project_id INT DEFAULT NULL,
            action VARCHAR(128) NOT NULL,
            details TEXT DEFAULT NULL,
            ip VARCHAR(45) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";

    // SETTINGS ----------------------------------------------------------
    $tables[] = "
        CREATE TABLE IF NOT EXISTS settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(128) NOT NULL UNIQUE,
            setting_value TEXT DEFAULT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";

    // E-MAIL TEMPLATES --------------------------------------------------
    $tables[] = "
        CREATE TABLE IF NOT EXISTS email_templates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(128) NOT NULL,
            subject VARCHAR(255) NOT NULL,
            body_html MEDIUMTEXT NOT NULL,
            body_text MEDIUMTEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";

    // PROJECT FILES -----------------------------------------------------
    $tables[] = "
        CREATE TABLE IF NOT EXISTS project_files (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,

            file_name VARCHAR(255) NOT NULL,
            original_name VARCHAR(255) DEFAULT NULL,
            mime_type VARCHAR(128) DEFAULT NULL,
            file_size BIGINT DEFAULT 0,

            content_enc LONGTEXT NOT NULL,
            sha256_plain CHAR(64) DEFAULT NULL,

            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

            INDEX idx_project_files_project (project_id),
            INDEX idx_project_files_name (project_id, file_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";

    $tables[] = "
    CREATE TABLE project_settings (
  project_id int NOT NULL,
  download_link varchar(255) DEFAULT NULL,
  session_expiration int NOT NULL DEFAULT '15',
  killswitch_enabled tinyint(1) NOT NULL DEFAULT '0',
  hwid_enabled tinyint(1) NOT NULL DEFAULT '1',
  dev_mode tinyint(1) NOT NULL DEFAULT '0',
  status enum('active','paused','archived', 'banned') NOT NULL DEFAULT 'active',
  updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;  
    ";


    $tables[] = "
    CREATE TABLE routes (
  id int UNSIGNED NOT NULL,
  menu_key enum('dashboard','management') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'dashboard',
  scope enum('global','project') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'global',
  project_id int UNSIGNED DEFAULT NULL,
  kind enum('group','item') COLLATE utf8mb4_unicode_ci NOT NULL,
  group_key varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  parent_id int UNSIGNED DEFAULT NULL,
  title varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  href varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  icon_var varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  icon varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  badge varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  roles_json json DEFAULT NULL,
  editable tinyint(1) NOT NULL DEFAULT '1',
  sort_order int NOT NULL DEFAULT '0',
  is_active tinyint(1) NOT NULL DEFAULT '1',
  created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";

    // DEFAULT ROLES -----------------------------------------------------
    $tables[] = "
        INSERT INTO roles
            (name, slug, project_limit, dev_mode_allowed, killswitch_allowed, hwid_toggle_allowed, custom_api_allowed, can_manage_users, can_manage_roles)
        VALUES
            ('Administrator', 'admin', 999, 1, 1, 1, 1, 1, 1)
        ON DUPLICATE KEY UPDATE
            name = VALUES(name);
    ";

    $tables[] = "
        INSERT INTO roles
            (name, slug, project_limit, dev_mode_allowed, killswitch_allowed, hwid_toggle_allowed, custom_api_allowed, can_manage_users, can_manage_roles)
        VALUES
            ('User', 'user', 10, 0, 0, 0, 0, 0, 0)
        ON DUPLICATE KEY UPDATE
            name = VALUES(name);
    ";



    // DEFAULT SETTINGS (SMTP + FROM) -----------------------------------
    // Wichtig: updated_at wird über die Table-Definition automatisch gesetzt,
    // daher können wir es im Insert weglassen.
    $tables[] = "
        INSERT INTO settings (`setting_key`, `setting_value`) VALUES
            ('smtp_host', NULL),
            ('smtp_port', NULL),
            ('smtp_user', NULL),
            ('smtp_pass', NULL),
            ('from_mail', NULL),
            ('from_name', NULL),
            ('Service_name', 'Authly')
        ON DUPLICATE KEY UPDATE
            `setting_value` = VALUES(`setting_value`),
            `updated_at` = CURRENT_TIMESTAMP;
    ";

    $tables[] = "
    INSERT INTO `routes` (`id`, `menu_key`, `scope`, `project_id`, `kind`, `group_key`, `parent_id`, `title`, `href`, `icon_var`, `icon`, `badge`, `roles_json`, `editable`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'both', 'global', NULL, 'group', 'sidebar', NULL, 'Home', NULL, NULL, NULL, NULL, NULL, 1, 10, 1, '2025-12-27 00:05:57', '2025-12-27 00:05:57'),
(2, 'both', 'global', NULL, 'item', NULL, 1, 'Dashboard', '/dashboard/', 'ico_leistungs', NULL, NULL, NULL, 1, 10, 1, '2025-12-27 00:05:57', '2025-12-27 00:05:57'),
(3, 'both', 'global', NULL, 'item', NULL, 1, 'Downloads', NULL, 'ico_conneciton', NULL, NULL, NULL, 1, 20, 1, '2025-12-27 00:05:57', '2025-12-27 00:05:57'),
(4, 'both', 'global', NULL, 'item', NULL, 3, 'C#', '../sources/Ressources/c%23.zip', 'ico_checklist', NULL, NULL, NULL, 1, 10, 1, '2025-12-27 00:05:57', '2025-12-27 00:05:57'),
(5, 'both', 'global', NULL, 'item', NULL, 3, 'C++', '../sources/Ressources/c++.zip', 'ico_checklist', NULL, NULL, NULL, 1, 20, 1, '2025-12-27 00:05:57', '2025-12-27 00:05:57'),
(6, 'both', 'global', NULL, 'item', NULL, 3, 'Java', '../sources/Ressources/java.zip', 'ico_checklist', NULL, NULL, NULL, 1, 30, 1, '2025-12-27 00:05:57', '2025-12-27 00:05:57'),
(7, 'both', 'global', NULL, 'item', NULL, 3, 'Python', '../sources/Ressources/python.zip', 'ico_checklist', NULL, NULL, NULL, 1, 40, 1, '2025-12-27 00:05:57', '2025-12-27 00:05:57'),
(8, 'both', 'global', NULL, 'item', NULL, 3, 'PHP', '../sources/Ressources/php.zip', 'ico_checklist', NULL, NULL, NULL, 1, 50, 1, '2025-12-27 00:05:57', '2025-12-27 00:05:57'),
(9, 'both', 'global', NULL, 'item', NULL, 1, 'Tools', NULL, 'ico_check', NULL, NULL, NULL, 1, 30, 1, '2025-12-27 00:05:57', '2025-12-27 00:05:57'),
(10, 'both', 'global', NULL, 'item', NULL, 9, 'SSL Cert', '../dashboard/validate.php', 'ico_checklist', NULL, NULL, NULL, 1, 10, 1, '2025-12-27 00:05:57', '2025-12-27 00:05:57'),
(11, 'both', 'global', NULL, 'item', NULL, 1, 'Discord', 'https://discord.gg/nFq8pjQjQw', 'ico_Onboard', NULL, NULL, NULL, 1, 40, 1, '2025-12-27 00:05:57', '2025-12-27 00:05:57'),
(12, 'management', 'global', NULL, 'group', 'management', NULL, 'Management', NULL, NULL, NULL, NULL, NULL, 1, 20, 1, '2025-12-27 00:06:04', '2025-12-27 00:06:04'),
(13, 'management', 'global', NULL, 'item', NULL, 12, 'Overview', '/dashboard/management.php', 'ico_circle', NULL, NULL, NULL, 1, 10, 1, '2025-12-27 00:06:04', '2025-12-27 00:06:04'),
(14, 'management', 'global', NULL, 'item', NULL, 12, 'Users', '/management/user.php', 'ico_Onboard', NULL, NULL, NULL, 1, 20, 1, '2025-12-27 00:06:04', '2025-12-27 00:06:04'),
(15, 'management', 'global', NULL, 'item', NULL, 12, 'Tokens', '/management/tokens.php', 'ico_checklist', NULL, NULL, NULL, 1, 30, 1, '2025-12-27 00:06:04', '2025-12-27 00:06:04'),
(16, 'management', 'global', NULL, 'item', NULL, 12, 'Variables', '/management/vars.php', 'ico_pen', NULL, NULL, NULL, 1, 40, 1, '2025-12-27 00:06:04', '2025-12-27 00:06:04'),
(17, 'management', 'global', NULL, 'item', NULL, 12, 'Files', '/management/files.php', 'ico_components', NULL, NULL, NULL, 1, 50, 1, '2025-12-27 00:06:04', '2025-12-27 00:06:04'),
(18, 'management', 'global', NULL, 'item', NULL, 12, 'Logs', '/management/logs.php', 'ico_components', NULL, NULL, NULL, 1, 60, 1, '2025-12-27 00:06:04', '2025-12-27 00:06:04'),
(19, 'management', 'global', NULL, 'item', NULL, 12, 'Settings', '/management/settings.php', 'ico_components', NULL, NULL, NULL, 1, 70, 1, '2025-12-27 00:06:04', '2025-12-27 00:06:04'),
(20, 'management', 'global', NULL, 'group', 'more', NULL, 'Admin', NULL, NULL, NULL, NULL, '[\"admin\"]', 1, 30, 1, '2025-12-27 00:06:04', '2025-12-27 00:06:04'),
(21, 'management', 'global', NULL, 'item', NULL, 20, 'Server', NULL, 'ico_Onboard', NULL, NULL, NULL, 1, 10, 1, '2025-12-27 00:06:05', '2025-12-27 00:06:05'),
(22, 'management', 'global', NULL, 'item', NULL, 21, 'Mail', '/admin/settings-mail.php', 'ico_circle', NULL, NULL, NULL, 1, 10, 1, '2025-12-27 00:06:05', '2025-12-27 00:06:05'),
(23, 'management', 'global', NULL, 'item', NULL, 21, 'Sign Up', '/register/', 'ico_checklist', NULL, NULL, NULL, 1, 20, 1, '2025-12-27 00:06:05', '2025-12-27 00:06:05');
";

    // AUSFÜHRUNG --------------------------------------------------------
    foreach ($tables as $sql) {
        $pdo->exec($sql);
    }

    return true;
}
?>
