<?php
// ============================================================================
// Authly Installer – Schritt 3 : Administrator-Erstellung
// ============================================================================

session_start();

$lockFile = __DIR__ . '/install.lock';
if (file_exists($lockFile)) {
    die('<h2 style="color:red;text-align:center;margin-top:50px;">
        🚫 Authly ist bereits installiert.<br><br>
        <a href="../login/" style="color:#4f46e5;text-decoration:none;font-weight:bold;">→ Zum Login</a>
    </h2>');
}

// Verbindung aufbauen
require_once __DIR__ . '/../db/config.php';
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die("<h3 style='color:red;text-align:center;'>❌ Datenbankfehler: {$e->getMessage()}</h3>");
}

$error   = '';
$success = false;

// Prüfen, ob schon ein Admin existiert
$check = $pdo->query("SELECT COUNT(*) FROM users WHERE role_id = 1")->fetchColumn();
if ($check > 0) {
    $alreadyInstalled = true;
} else {
    $alreadyInstalled = false;
}

// Formularverarbeitung
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$alreadyInstalled) {
    $username = trim($_POST['username']);
    $email    = trim($_POST['email']);
    $password = $_POST['password'];

    if (empty($username) || empty($email) || empty($password)) {
        $error = 'Bitte alle Felder ausfüllen.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Ungültige E-Mail-Adresse.';
    } else {
        try {
            $hash = password_hash($password, PASSWORD_ARGON2ID);
            $uuid = bin2hex(random_bytes(16));
            $stmt = $pdo->prepare("
                INSERT INTO users (uuid, username, email, password, role_id, status, created_at)
                VALUES (:uuid, :username, :email, :password, 1, 'active', NOW())
            ");
            $stmt->execute([
                ':uuid'     => $uuid,
                ':username' => $username,
                ':email'    => $email,
                ':password' => $hash,
            ]);
            $success = true;
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Authly Setup – Administrator anlegen</title>
<link rel="stylesheet" href="../gui/css/install.css">
<style>
form{display:flex;flex-direction:column;gap:15px;margin-top:20px}
input{padding:12px;border-radius:8px;border:none;outline:none;width:100%;font-size:1rem}
.error{color:#f87171;font-weight:600;margin-top:10px}
.success{color:#4ade80;font-weight:600;margin-top:10px}
.btn-secondary{background:rgba(255,255,255,0.3);color:#fff;margin-top:15px}
.btn-secondary:hover{background:rgba(255,255,255,0.5)}
</style>
</head>
<body>

<div class="setup-box">
<h1>👤 Schritt 3 – Administrator anlegen</h1>

<?php if ($alreadyInstalled): ?>
    <p>Ein Administrator-Account existiert bereits.<br>Du kannst direkt fortfahren.</p>
    <form action="step4_mail.php" method="get">
        <button type="submit" class="btn">Weiter</button>
    </form>

<?php elseif (!$success): ?>
    <p>Erstelle jetzt den ersten Administrator-Account, um dich später im Dashboard anzumelden.</p>

    <?php if ($error): ?>
        <p class="error">❌ <?php echo htmlspecialchars($error); ?></p>
    <?php endif; ?>

    <form method="POST" action="">
        <input type="text" name="username" placeholder="Benutzername" required>
        <input type="email" name="email" placeholder="E-Mail-Adresse" required>
        <input type="password" name="password" placeholder="Passwort" required>
        <button type="submit" class="btn">Administrator erstellen</button>
    </form>

    <form action="step2_database.php" method="get">
        <button type="submit" class="btn btn-secondary">← Zurück</button>
    </form>

<?php else: ?>
    <p class="success">✅ Administrator erfolgreich erstellt!</p>
    <p>Du kannst dich später mit diesen Zugangsdaten anmelden.</p>
    <form action="step4_mail.php" method="get">
        <button type="submit" class="btn">Weiter</button>
    </form>
<?php endif; ?>
</div>

<footer>© <?php echo date('Y'); ?> Authly Installer</footer>

</body>
</html>
