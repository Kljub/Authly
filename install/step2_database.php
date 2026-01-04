<?php
// ============================================================================
// Authly Installer - Schritt 2: Datenbankverbindung
// ============================================================================

// Prüfen ob bereits installiert
$lockFile = __DIR__ . '/install.lock';
if (file_exists($lockFile)) {
    die('<h2 style="color:red;text-align:center;margin-top:50px;">
        🚫 Authly ist bereits installiert.<br><br>
        <a href="../login/" style="color:#4f46e5;text-decoration:none;font-weight:bold;">→ Zum Login</a>
    </h2>');
}

$error = '';
$success = false;

// Wenn das Formular abgesendet wurde
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbHost = trim($_POST['db_host']);
    $dbUser = trim($_POST['db_user']);
    $dbPass = trim($_POST['db_pass']);
    $dbName = trim($_POST['db_name']);

    try {
        // Verbindung zur Datenbank aufbauen
        $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Tabellen erstellen über den Installer im /db/ Ordner
        require_once __DIR__ . '/../db/installer.php';
        authly_install_tables($pdo);

        // Zufälligen Security Key generieren
        $securityKey = bin2hex(random_bytes(16));

        // Config-Datei erstellen (/db/config.php)
        $configContent = "<?php\n";
        $configContent .= "// ============================================================================\n";
        $configContent .= "// Authly – Konfiguration\n";
        $configContent .= "// ============================================================================\n\n";
        $configContent .= "define('DB_HOST', '$dbHost');\n";
        $configContent .= "define('DB_NAME', '$dbName');\n";
        $configContent .= "define('DB_USER', '$dbUser');\n";
        $configContent .= "define('DB_PASS', '$dbPass');\n";
        $configContent .= "define('DB_CHARSET', 'utf8mb4');\n\n";
        $configContent .= "define('APP_NAME', 'Authly');\n";
        $configContent .= "define('APP_VERSION', '1.0.0');\n";
        $configContent .= "define('AUTHLY_KEY', '$securityKey');\n";
        $configContent .= "date_default_timezone_set('Europe/Berlin');\n";
        $configContent .= "?>";

        // Speichern der Config
        $configPath = __DIR__ . '/../db/config.php';
        file_put_contents($configPath, $configContent);

        $success = true;
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Authly Setup – Datenbank</title>
    <link rel="stylesheet" href="../gui/css/install.css">
    <style>
        form {
            display: flex;
            flex-direction: column;
            gap: 15px;
            margin-top: 20px;
        }
        input {
            padding: 12px;
            border-radius: 8px;
            border: none;
            outline: none;
            width: 100%;
            font-size: 1rem;
        }
        .error {
            color: #f87171;
            font-weight: 600;
            margin-top: 10px;
        }
        .success {
            color: #4ade80;
            font-weight: 600;
            margin-top: 10px;
        }
        .btn-secondary {
            background: rgba(255,255,255,0.3);
            color: #fff;
            margin-top: 15px;
        }
        .btn-secondary:hover {
            background: rgba(255,255,255,0.5);
        }
    </style>
</head>
<body>

    <div class="setup-box">
        <h1>⚙️ Schritt 2 – Datenbankverbindung</h1>

        <?php if (!$success): ?>
            <p>Bitte gib deine Datenbankinformationen ein.<br>Diese werden in der <strong>/db/config.php</strong> gespeichert.</p>

            <?php if ($error): ?>
                <p class="error">❌ <?php echo htmlspecialchars($error); ?></p>
            <?php endif; ?>

            <form method="POST" action="">
                <input type="text" name="db_host" placeholder="Host (z. B. localhost)" required>
                <input type="text" name="db_name" placeholder="Datenbankname" required>
                <input type="text" name="db_user" placeholder="Benutzername" required>
                <input type="password" name="db_pass" placeholder="Passwort (optional)">
                <button type="submit" class="btn">Verbindung testen & installieren</button>
            </form>

            <form action="step1_requirements.php" method="get">
                <button type="submit" class="btn btn-secondary">← Zurück</button>
            </form>

        <?php else: ?>
            <p class="success">✅ Datenbank erfolgreich verbunden & Tabellen erstellt!</p>
            <p>Deine Konfiguration wurde in <strong>/db/config.php</strong> gespeichert.</p>

            <form action="step3_admin.php" method="get">
                <button type="submit" class="btn">Weiter zu Schritt 3</button>
            </form>
        <?php endif; ?>
    </div>

    <footer>© <?php echo date('Y'); ?> Authly Installer</footer>

</body>
</html>
