<?php
// ============================================================================
// AUTHLY – REGISTER (mit Captcha-Gate vor dem Formular)
// PFAD: /register/index.php
// ============================================================================

session_start();
require_once __DIR__ . '/../db/config.php';

/**
 * WICHTIG:
 * Das Captcha (/captcha/index.php) setzt: $_SESSION['captcha_token'] = ['payload','sig','used']
 * Hier prüfen wir das sauber (Signatur + Ablauf + One-Time-Use).
 */
if (!defined('AUTHLY_CAPTCHA_SECRET')) {
    // MUSS identisch sein wie in /captcha/index.php
    define('AUTHLY_CAPTCHA_SECRET', 'CHANGE_ME_TO_A_LONG_RANDOM_SECRET_64CHARS_MIN');
}

function authly_captcha_hmac(string $payload): string {
    return hash_hmac('sha256', $payload, AUTHLY_CAPTCHA_SECRET);
}

function authly_captcha_is_valid_session_token(): bool {
    if (empty($_SESSION['captcha_token']) || !is_array($_SESSION['captcha_token'])) return false;

    $ct = $_SESSION['captcha_token'];
    if (empty($ct['payload']) || empty($ct['sig'])) return false;

    if (!empty($ct['used'])) return false; // One-time

    $payload = (string)$ct['payload'];
    $sig     = (string)$ct['sig'];

    $expect = authly_captcha_hmac($payload);
    if (!hash_equals($expect, $sig)) return false;

    $data = json_decode($payload, true);
    if (!is_array($data)) return false;

    $exp   = (int)($data['exp'] ?? 0);
    $score = (int)($data['score'] ?? 0);

    if ($exp <= 0 || time() > $exp) return false;
    if ($score < 85) return false;

    return true;
}

function authly_captcha_mark_used(): void {
    if (!empty($_SESSION['captcha_token']) && is_array($_SESSION['captcha_token'])) {
        $_SESSION['captcha_token']['used'] = 1;
    }
}

/**
 * CSRF Token
 */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

/**
 * AJAX: Captcha Status abfragen
 */
if (isset($_GET['action']) && $_GET['action'] === 'captcha_status') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => authly_captcha_is_valid_session_token()]);
    exit;
}

/**
 * DB Connection
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
$success = '';

function authly_get_default_role_id(PDO $pdo): int {
    try {
        $st = $pdo->query("SELECT id FROM roles WHERE slug <> 'admin' ORDER BY id ASC LIMIT 1");
        $rid = (int)($st->fetchColumn() ?: 0);
        if ($rid > 0) return $rid;
    } catch (Throwable $e) {}
    return 1;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_register'])) {

    if (!authly_captcha_is_valid_session_token()) {
        $error = 'Captcha ist ungültig oder abgelaufen. Bitte neu verifizieren.';
    } else {
        if (!isset($_POST['csrf_token'], $_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            $error = 'Sicherheitsfehler. Bitte Seite neu laden.';
        } else {

            $username = trim($_POST['username'] ?? '');
            $email    = trim($_POST['email'] ?? '');
            $pass1    = (string)($_POST['password'] ?? '');
            $pass2    = (string)($_POST['password_confirm'] ?? '');

            if ($username === '' || $email === '' || $pass1 === '' || $pass2 === '') {
                $error = 'Bitte alle Felder ausfüllen.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Bitte eine gültige E-Mail angeben.';
            } elseif (mb_strlen($username) < 3 || mb_strlen($username) > 64) {
                $error = 'Username muss 3–64 Zeichen lang sein.';
            } elseif ($pass1 !== $pass2) {
                $error = 'Passwörter stimmen nicht überein.';
            } elseif (mb_strlen($pass1) < 8) {
                $error = 'Passwort muss mindestens 8 Zeichen haben.';
            } else {

                $st = $pdo->prepare("SELECT id FROM users WHERE email = :e OR username = :u LIMIT 1");
                $st->execute([':e' => $email, ':u' => $username]);
                $exists = $st->fetch(PDO::FETCH_ASSOC);

                if ($exists) {
                    $error = 'Username oder E-Mail ist bereits vergeben.';
                } else {
                    $hash   = password_hash($pass1, PASSWORD_ARGON2ID);
                    $uuid   = bin2hex(random_bytes(16));
                    $roleId = authly_get_default_role_id($pdo);

                    $ins = $pdo->prepare("
                        INSERT INTO users (uuid, username, email, password, role_id, status, created_at, ip_address)
                        VALUES (:uuid, :u, :e, :p, :rid, 'active', NOW(), :ip)
                    ");

                    $ok = $ins->execute([
                        ':uuid' => $uuid,
                        ':u'    => $username,
                        ':e'    => $email,
                        ':p'    => $hash,
                        ':rid'  => $roleId,
                        ':ip'   => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                    ]);

                    if ($ok) {
                        authly_captcha_mark_used();
                        header('Location: /login/?registered=1');
                        exit;
                    } else {
                        $error = 'Fehler beim Erstellen des Accounts.';
                    }
                }
            }
        }
    }
}

$captchaOk = authly_captcha_is_valid_session_token();
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="utf-8" />
    <title>Authly – Sign Up</title>
    <meta name="viewport" content="width=device-width,initial-scale=1" />

    <link href="/gui/css/vendors/aos.css" rel="stylesheet" />
    <link href="/gui/css/signinv2.css" rel="stylesheet" />
    <link rel="icon" href="/gui/images/favicon.svg" />

    <script>
        if (localStorage.getItem('dark-mode') === 'false' || !('dark-mode' in localStorage)) {
            document.documentElement.classList.remove('dark');
            document.documentElement.style.colorScheme = 'light';
        } else {
            document.documentElement.classList.add('dark');
            document.documentElement.style.colorScheme = 'dark';
        }
    </script>

    <style>
        .card-dark {
            border-radius: 16px;
            border: 1px solid rgba(148,163,184,0.15);
            background: rgba(2,6,23,0.25);
            box-shadow: 0 30px 80px rgba(0,0,0,0.45);
            overflow: hidden;
        }
        .btn-soft {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            height: 42px;
            padding: 0 18px;
            font-size: 0.875rem;
            font-weight: 600;
        }
    </style>
</head>

<body class="font-inter antialiased bg-slate-900 text-slate-200 tracking-tight">
<div class="flex flex-col min-h-screen overflow-hidden supports-[overflow:clip]:overflow-clip">
    <main class="grow">
        <section class="relative">

            <div class="md:block absolute left-1/2 -translate-x-1/2 -mt-36 blur-2xl opacity-70 pointer-events-none -z-10" aria-hidden="true">
                <img src="/gui/images/auth-illustration.svg" class="max-w-none" width="1440" height="450" alt="Page Illustration">
            </div>

            <div class="relative max-w-6xl mx-auto px-4 sm:px-6">
                <div class="pt-32 pb-12 md:pt-40 md:pb-20">

                    <div class="max-w-3xl mx-auto text-center pb-12">
                        <div class="mb-5">
                            <a class="inline-flex" href="/">
                                <div class="relative flex items-center justify-center w-16 h-16 border border-transparent rounded-2xl shadow-2xl [background:linear-gradient(var(--color-slate-900),var(--color-slate-900))_padding-box,conic-gradient(var(--color-slate-400),var(--color-slate-700)_25%,var(--color-slate-700)_75%,var(--color-slate-400)_100%)_border-box] before:absolute before:inset-0 before:bg-slate-800/30 before:rounded-2xl">
                                    <img class="relative" src="/gui/images/logo.svg" width="42" height="42" alt="Authly">
                                </div>
                            </a>
                        </div>

                        <h1 class="h2 bg-clip-text text-transparent bg-linear-to-r from-slate-200/60 via-slate-200 to-slate-200/60">
                            Create your account
                        </h1>
                        <p class="text-sm text-slate-400 mt-2">
                            <?php if (!$captchaOk): ?>
                                Please complete the captcha first.
                            <?php else: ?>
                                Fill in your details to sign up.
                            <?php endif; ?>
                        </p>
                    </div>

                    <!-- WICHTIG: Captcha-Gate breiter, Formular bleibt schmal -->
                    <div class="<?= $captchaOk ? 'max-w-sm' : 'max-w-2xl' ?> mx-auto">

                        <?php if (!empty($error)): ?>
                            <div class="mb-4 rounded-lg px-3 py-2 bg-red-500/15 text-red-300 border border-red-500/30">
                                <?= htmlspecialchars($error) ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($success)): ?>
                            <div class="mb-4 rounded-lg px-3 py-2 bg-green-500/15 text-green-300 border border-green-500/30">
                                <?= htmlspecialchars($success) ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!$captchaOk): ?>
                            <!-- CAPTCHA GATE (größer + ohne Enge) -->
                            <div class="card-dark">
                                <div class="p-6">
                                    <div class="text-sm text-slate-300 font-semibold mb-2">Captcha</div>
                                    <div class="text-xs text-slate-400 mb-4">
                                        Bewege die Maus im Feld, bis du verifiziert bist, dann klicke „Verify“.
                                    </div>

                                    <div class="rounded-2xl overflow-hidden border border-slate-800 bg-slate-950/20">
                                        <!-- höher, damit keine iFrame-Scrollbars entstehen -->
                                        <iframe
                                            id="captchaFrame"
                                            src="/captcha/"
                                            style="width:100%;height:620px;border:0;display:block;background:transparent;"
                                            loading="lazy"
                                        ></iframe>
                                    </div>

                                    <div class="mt-5 flex items-center justify-between gap-4 flex-wrap">
                                        <a class="text-sm font-medium text-purple-500 hover:text-purple-400 transition duration-150 ease-in-out"
                                           href="/login/">Already have an account?</a>

                                        <button type="button" id="btnContinue"
                                                class="btn-soft text-white bg-purple-500 hover:bg-purple-600 shadow-xs disabled:opacity-40 disabled:cursor-not-allowed"
                                                disabled>
                                            Continue
                                            <span class="tracking-normal text-purple-200 ml-2">-&gt;</span>
                                        </button>
                                    </div>

                                    <div id="capMsg" class="mt-3 text-xs text-slate-400"></div>
                                </div>
                            </div>

                            <script>
                                (function(){
                                    const btn = document.getElementById('btnContinue');
                                    const msg = document.getElementById('capMsg');

                                    async function poll() {
                                        try {
                                            const r = await fetch('index.php?action=captcha_status', { cache: 'no-store' });
                                            const j = await r.json();
                                            if (j && j.ok) {
                                                btn.disabled = false;
                                                msg.textContent = 'Captcha OK ✅ Du kannst fortfahren.';
                                                msg.className = 'mt-3 text-xs text-green-300';
                                            } else {
                                                btn.disabled = true;
                                                msg.textContent = 'Noch nicht verifiziert…';
                                                msg.className = 'mt-3 text-xs text-slate-400';
                                            }
                                        } catch(e) {}
                                    }

                                    btn.addEventListener('click', () => {
                                        window.location.href = '/register/?captcha=1';
                                    });

                                    poll();
                                    setInterval(poll, 900);
                                })();
                            </script>

                        <?php else: ?>
                            <!-- REGISTER FORM -->
                            <form method="post" action="">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>" />
                                <input type="hidden" name="do_register" value="1" />

                                <div class="space-y-4">
                                    <div>
                                        <label class="block text-sm text-slate-300 font-medium mb-1" for="username">Username</label>
                                        <input id="username" name="username" class="form-input w-full" type="text" minlength="3" maxlength="64" required />
                                    </div>

                                    <div>
                                        <label class="block text-sm text-slate-300 font-medium mb-1" for="email">Email</label>
                                        <input id="email" name="email" class="form-input w-full" type="email" required />
                                    </div>

                                    <div>
                                        <label class="block text-sm text-slate-300 font-medium mb-1" for="password">Password</label>
                                        <input id="password" name="password" class="form-input w-full" type="password" autocomplete="new-password" required />
                                    </div>

                                    <div>
                                        <label class="block text-sm text-slate-300 font-medium mb-1" for="password_confirm">Confirm Password</label>
                                        <input id="password_confirm" name="password_confirm" class="form-input w-full" type="password" autocomplete="new-password" required />
                                    </div>
                                </div>

                                <div class="mt-6">
                                    <button type="submit" class="btn text-sm text-white bg-purple-500 hover:bg-purple-600 w-full shadow-xs group">
                                        Sign Up
                                        <span class="tracking-normal text-purple-300 group-hover:translate-x-0.5 transition-transform duration-150 ease-in-out ml-1">-&gt;</span>
                                    </button>
                                </div>
                            </form>

                            <div class="text-center mt-4">
                                <div class="text-sm text-slate-400">
                                    Already have an account?
                                    <a class="font-medium text-purple-500 hover:text-purple-400 transition duration-150 ease-in-out" href="/login/">Sign in</a>
                                </div>
                            </div>
                        <?php endif; ?>

                    </div><!-- /wrapper -->

                </div>
            </div>

        </section>
    </main>
</div>

<script src="/gui/js/vendors/alpinejs.min.js" defer></script>
<script src="/gui/js/vendors/aos.js"></script>
<script src="/gui/js/main.js"></script>
</body>
</html>
