<?php
// ============================================================================
// Authly Installer - index.php
// ============================================================================

// Prüfe, ob Authly bereits installiert ist
$lockFile = __DIR__ . '/install.lock';
if (file_exists($lockFile)) {
    die('<h2 style="color:red;text-align:center;margin-top:50px;">
        🚫 Authly ist bereits installiert.<br><br>
        <a href="../login/" style="color:#4f46e5;text-decoration:none;font-weight:bold;">
            → Zum Login
        </a>
    </h2>');
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Authly Setup</title>

    <!-- Authly GUI Styles -->
    <link rel="stylesheet" href="../gui/css/install.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">

    <style>
        body {
            font-family: "Poppins", sans-serif;
            background: linear-gradient(135deg, #4f46e5, #6d28d9);
            color: #fff;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100vh;
            margin: 0;
            overflow: hidden;
        }
        .setup-box {
            background: rgba(255, 255, 255, 0.1);
            padding: 50px 70px;
            border-radius: 20px;
            text-align: center;
            box-shadow: 0 0 25px rgba(0, 0, 0, 0.2);
            max-width: 480px;
        }
        .setup-box h1 {
            font-size: 2.2rem;
            font-weight: 600;
            margin-bottom: 15px;
        }
        .setup-box p {
            font-size: 1rem;
            opacity: 0.9;
            margin-bottom: 30px;
            line-height: 1.6;
        }
        .btn {
            background: #fff;
            color: #4f46e5;
            border: none;
            padding: 14px 40px;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            font-size: 1rem;
            transition: all 0.3s ease;
        }
        .btn:hover {
            background: #f1f1f1;
            transform: scale(1.05);
        }
        footer {
            position: absolute;
            bottom: 25px;
            font-size: 0.9rem;
            opacity: 0.7;
            text-align: center;
        }
    </style>
</head>
<body>

    <div class="setup-box">
        <h1>👋 Willkommen bei Authly</h1>
        <p>Dieser Setup-Assistent hilft dir, Authly in wenigen Schritten zu installieren.<br>
        Stelle sicher, dass du deine Datenbank-Zugangsdaten bereithältst.</p>
        
        <form action="step1_requirements.php" method="get">
            <button type="submit" class="btn">Installation starten</button>
        </form>
    </div>

    <footer>© <?php echo date('Y'); ?> Authly Installer</footer>

</body>
</html>
