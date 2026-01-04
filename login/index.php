<?php
session_start();
require_once __DIR__ . '/../db/config.php';

/**
 * CSRF-Token bereitstellen (einfach & wirksam)
 */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/**
 * Datenbankverbindung
 */
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

$error = '';

/**
 * Login-Handling
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF prüfen
    if (!isset($_POST['csrf_token'], $_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = 'Sicherheitsfehler. Bitte Seite neu laden.';
    } else {
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($email === '' || $password === '') {
            $error = 'Bitte E-Mail und Passwort ausfüllen.';
        } else {
            $stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['password'])) {
                // Session setzen
                $_SESSION['user_id']   = (int)$user['id'];
                $_SESSION['username']  = $user['username'];
                $_SESSION['role_id']   = (int)$user['role_id'];
                $_SESSION['is_admin']  = ((int)$user['role_id'] === 1);
                $_SESSION['logged_in'] = true;

                // Letzten Login + IP speichern
                $upd = $pdo->prepare('UPDATE users SET last_login = NOW(), ip_address = :ip WHERE id = :id');
                $upd->execute([
                    ':ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                    ':id' => $user['id'],
                ]);

                // Weiter ins Dashboard
                header('Location: /dashboard/');
                exit;
            } else {
                $error = 'E-Mail oder Passwort ist ungültig.';
            }
        }
    }
}

// Für Formular
$csrf = $_SESSION['csrf_token'];
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="utf-8" />
    <title>Authly – Sign In</title>
    <meta name="viewport" content="width=device-width,initial-scale=1" />

    <!-- ✅ Deine kompilierten Assets mit absoluten Pfaden (nginx-freundlich) -->
    <link href="/gui/css/vendors/aos.css" rel="stylesheet" />
    <link href="/gui/css/signinv2.css" rel="stylesheet" />
    <link rel="icon" href="/gui/images/favicon.svg" />

    <script>
        // Dark Mode Handling wie im Mosaic-Template
        if (localStorage.getItem('dark-mode') === 'false' || !('dark-mode' in localStorage)) {
            document.documentElement.classList.remove('dark');
            document.documentElement.style.colorScheme = 'light';
        } else {
            document.documentElement.classList.add('dark');
            document.documentElement.style.colorScheme = 'dark';
        }
    </script>
</head>
<body class="font-inter antialiased bg-slate-900 text-slate-200 tracking-tight">

    <!-- Wrapper -->
    <div class="flex flex-col min-h-screen overflow-hidden supports-[overflow:clip]:overflow-clip">
        <main class="grow">
            <section class="relative">

                <!-- Illustration -->
                <div class="md:block absolute left-1/2 -translate-x-1/2 -mt-36 blur-2xl opacity-70 pointer-events-none -z-10" aria-hidden="true">
                    <img src="/gui/images/auth-illustration.svg" class="max-w-none" width="1440" height="450" alt="Page Illustration">
                </div>

                <div class="relative max-w-6xl mx-auto px-4 sm:px-6">
                    <div class="pt-32 pb-12 md:pt-40 md:pb-20">

                        <!-- Header -->
                        <div class="max-w-3xl mx-auto text-center pb-12">
                            <div class="mb-5">
                                <a class="inline-flex" href="/">
                                    <div class="relative flex items-center justify-center w-16 h-16 border border-transparent rounded-2xl shadow-2xl [background:linear-gradient(var(--color-slate-900),var(--color-slate-900))_padding-box,conic-gradient(var(--color-slate-400),var(--color-slate-700)_25%,var(--color-slate-700)_75%,var(--color-slate-400)_100%)_border-box] before:absolute before:inset-0 before:bg-slate-800/30 before:rounded-2xl">
                                        <img class="relative" src="/gui/images/logo.svg" width="42" height="42" alt="Authly">
                                    </div>
                                </a>
                            </div>
                            <h1 class="h2 bg-clip-text text-transparent bg-linear-to-r from-slate-200/60 via-slate-200 to-slate-200/60">
                                Sign in to your account
                            </h1>
                        </div>

                        <!-- Form -->
                        <div class="max-w-sm mx-auto">

                            <?php if (!empty($error)): ?>
                                <div class="mb-4 rounded-lg px-3 py-2 bg-red-500/15 text-red-300 border border-red-500/30">
                                    <?= htmlspecialchars($error) ?>
                                </div>
                            <?php endif; ?>

                            <form method="post" action="">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>" />
                                <div class="space-y-4">
                                    <div>
                                        <label class="block text-sm text-slate-300 font-medium mb-1" for="email">Email</label>
                                        <input id="email" name="email" class="form-input w-full" type="email" required />
                                    </div>
                                    <div>
                                        <div class="flex justify-between">
                                            <label class="block text-sm text-slate-300 font-medium mb-1" for="password">Password</label>
                                            <a class="text-sm font-medium text-purple-500 hover:text-purple-400 transition duration-150 ease-in-out ml-2" href="/reset-password/">Forgot?</a>
                                        </div>
                                        <input id="password" name="password" class="form-input w-full" type="password" autocomplete="on" required />
                                    </div>
                                </div>
                                <div class="mt-6">
                                    <button type="submit" class="btn text-sm text-white bg-purple-500 hover:bg-purple-600 w-full shadow-xs group">
                                        Sign In
                                        <span class="tracking-normal text-purple-300 group-hover:translate-x-0.5 transition-transform duration-150 ease-in-out ml-1">-&gt;</span>
                                    </button>
                                </div>
                            </form>

                            <div class="text-center mt-4">
                                <div class="text-sm text-slate-400">
                                    Don't have an account?
                                    <a class="font-medium text-purple-500 hover:text-purple-400 transition duration-150 ease-in-out" href="/register/">Sign up</a>
                                </div>
                            </div>

                            <!-- Divider -->
                            <div class="flex items-center my-6">
                                <div class="border-t border-slate-800 grow mr-3" aria-hidden="true"></div>
                                <div class="text-sm text-slate-500 italic">or</div>
                                <div class="border-t border-slate-800 grow ml-3" aria-hidden="true"></div>
                            </div>

                            <!-- Social (Demo-Buttons) -->
                            <div class="flex space-x-3">
                                <button class="btn text-slate-300 hover:text-white transition duration-150 ease-in-out w-full group [background:linear-gradient(var(--color-slate-900),var(--color-slate-900))_padding-box,conic-gradient(var(--color-slate-400),var(--color-slate-700)_25%,var(--color-slate-700)_75%,var(--color-slate-400)_100%)_border-box] relative before:absolute before:inset-0 before:bg-slate-800/30 before:rounded-full before:pointer-events-none h-9" type="button">
                                    <span class="relative">
                                        <span class="sr-only">Continue with Twitter</span>
                                        <svg class="fill-current" xmlns="http://www.w3.org/2000/svg" width="14" height="12"><path d="m4.34 0 2.995 3.836L10.801 0h2.103L8.311 5.084 13.714 12H9.482L6.169 7.806 2.375 12H.271l4.915-5.436L0 0h4.34Zm-.635 1.155H2.457l7.607 9.627h1.165L3.705 1.155Z"/></svg>
                                    </span>
                                </button>
                                <button class="btn text-slate-300 hover:text-white transition duration-150 ease-in-out w-full group [background:linear-gradient(var(--color-slate-900),var(--color-slate-900))_padding-box,conic-gradient(var(--color-slate-400),var(--color-slate-700)_25%,var(--color-slate-700)_75%,var(--color-slate-400)_100%)_border-box] relative before:absolute before:inset-0 before:bg-slate-800/30 before:rounded-full before:pointer-events-none h-9" type="button">
                                    <span class="relative">
                                        <span class="sr-only">Continue with GitHub</span>
                                        <svg class="fill-current" xmlns="http://www.w3.org/2000/svg" width="16" height="15"><path d="M7.488 0C3.37 0 0 3.37 0 7.488c0 3.276 2.153 6.084 5.148 7.113.374.094.468-.187.468-.374v-1.31c-2.06.467-2.527-.936-2.527-.936-.375-.843-.843-1.124-.843-1.124-.655-.468.094-.468.094-.468.749.094 1.123.75 1.123.75.655 1.216 1.778.842 2.153.654.093-.468.28-.842.468-1.03-1.685-.186-3.37-.842-3.37-3.743 0-.843.281-1.498.75-1.966-.094-.187-.375-.936.093-1.965 0 0 .655-.187 2.059.749a6.035 6.035 0 0 1 1.872-.281c.655 0 1.31.093 1.872.28 1.404-.935 2.059-.748 2.059-.748.374 1.03.187 1.778.094 1.965.468.562.748 1.217.748 1.966 0 2.901-1.778 3.463-3.463 3.65.281.375.562.843.562 1.498v2.059c0 .187.093.468.561.374 2.996-1.03 5.148-3.837 5.148-7.113C14.976 3.37 11.606 0 7.488 0Z"/></svg>
                                    </span>
                                </button>
                            </div>

                        </div><!-- /max-w-sm -->

                    </div>
                </div>

            </section>
        </main>
    </div>

    <!-- ✅ Vendor/JS -->
    <script src="/gui/js/vendors/alpinejs.min.js" defer></script>
    <script src="/gui/js/vendors/aos.js"></script>
    <script src="/gui/js/main.js"></script>
</body>
</html>
