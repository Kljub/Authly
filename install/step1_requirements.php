<?php
// ============================================================================
// Authly Installer - Step 1: Systemprüfung
// ============================================================================

$lockFile = __DIR__ . '/install.lock';
if (file_exists($lockFile)) {
    die('<h2 style="color:red;text-align:center;margin-top:50px;">
        🚫 Authly ist bereits installiert.<br><br>
        <a href="../login/" style="color:#4f46e5;text-decoration:none;font-weight:bold;">→ Zum Login</a>
    </h2>');
}

// Prüfkriterien
$requirements = [
    'PHP Version >= 8.0' => version_compare(PHP_VERSION, '8.0', '>='),
    'PDO aktiv' => extension_loaded('pdo'),
    'MySQL-Treiber installiert' => extension_loaded('pdo_mysql'),
    'cURL aktiviert' => extension_loaded('curl'),
    'OpenSSL aktiv' => extension_loaded('openssl'),
    'JSON-Unterstützung' => extension_loaded('json'),

    // Prüft, ob /db/config.php existiert UND schreibbar ist,
    // oder ob der /db/ Ordner beschreibbar ist (für den Fall, dass die Datei noch erstellt werden muss)
    'Schreibrechte im config.php' => (
        (file_exists(__DIR__ . '/../db/config.php') && is_writable(__DIR__ . '/../db/config.php'))
        || is_writable(__DIR__ . '/../db')
    ),

    // Prüft, ob der Installationsordner beschreibbar ist (für install.lock)
    'Schreibrechte im Installationsordner' => is_writable(__DIR__),
];


// Gesamtstatus prüfen
$allGood = !in_array(false, $requirements, true);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Authly Setup – Systemprüfung</title>
    <link rel="stylesheet" href="../gui/css/install.css">
    <style>
        table {
            width: 100%;
            margin-top: 20px;
            border-collapse: collapse;
        }
        th, td {
            padding: 10px 15px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            text-align: left;
        }
        th {
            font-weight: 600;
            opacity: 0.9;
        }
        .ok {
            color: #4ade80;
            font-weight: bold;
        }
        .fail {
            color: #f87171;
            font-weight: bold;
        }
        .btn-disabled {
            opacity: 0.5;
            pointer-events: none;
        }
        .note {
            font-size: 0.9rem;
            opacity: 0.8;
            margin-top: 15px;
        }
    </style>
</head>
<body>

    <div class="setup-box">
        <h1>🧩 Schritt 1 – Systemprüfung</h1>
        <p>Wir prüfen nun, ob dein Server für Authly bereit ist.</p>

        <table>
            <tr><th>Komponente</th><th>Status</th></tr>
            <?php foreach ($requirements as $req => $status): ?>
                <tr>
                    <td><?php echo htmlspecialchars($req); ?></td>
                    <td class="<?php echo $status ? 'ok' : 'fail'; ?>">
                        <?php echo $status ? '✅ OK' : '❌ Fehler'; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>

        <?php if ($allGood): ?>
            <p class="note">Alle Voraussetzungen sind erfüllt – du kannst fortfahren.</p>
            <form action="step2_database.php" method="get">
                <button type="submit" class="btn">Weiter zu Schritt 2</button>
            </form>
        <?php else: ?>
            <p class="note" style="color:#f87171;">Einige Voraussetzungen sind nicht erfüllt.<br>Bitte behebe die Fehler und lade die Seite neu.</p>
            <button class="btn btn-disabled">Fortfahren nicht möglich</button>
        <?php endif; ?>
    </div>

    <footer>© <?php echo date('Y'); ?> Authly Installer</footer>

</body>
</html>
