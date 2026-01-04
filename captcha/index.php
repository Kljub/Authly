<?php
// ============================================================================
// AUTHLY – VISUAL CAPTCHA (Mouse/Touch Progress + Static Trails, Random Colors)
// PFAD: /captcha/index.php
// ============================================================================

session_start();

// Optional: Secret zentral definieren (besser in /db/config.php)
// define('AUTHLY_CAPTCHA_SECRET', '...random...');
if (!defined('AUTHLY_CAPTCHA_SECRET')) {
    define('AUTHLY_CAPTCHA_SECRET', 'CHANGE_ME_TO_A_LONG_RANDOM_SECRET_64CHARS_MIN');
}

// --- Helpers (server-side token) ---
function authly_captcha_hmac(string $payload): string {
    return hash_hmac('sha256', $payload, AUTHLY_CAPTCHA_SECRET);
}

function authly_captcha_issue_token(int $score, array $meta = []): array {
    $issuedAt = time();
    $expires  = $issuedAt + 120; // 2 min
    $nonce    = bin2hex(random_bytes(16));

    $payload = json_encode([
        'nonce' => $nonce,
        'iat'   => $issuedAt,
        'exp'   => $expires,
        'score' => $score,
        'meta'  => $meta,
    ], JSON_UNESCAPED_SLASHES);

    $sig = authly_captcha_hmac($payload);

    $_SESSION['captcha_token'] = [
        'payload' => $payload,
        'sig'     => $sig,
        'used'    => 0
    ];

    return ['ok' => true, 'expires' => $expires];
}

// ============================================================================
// AJAX endpoint in derselben Datei (kein extra file nötig)
// POST ?action=verify  -> {score, metrics} -> session token
// ============================================================================

if (isset($_GET['action']) && $_GET['action'] === 'verify') {
    header('Content-Type: application/json; charset=utf-8');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
        exit;
    }

    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Bad JSON']);
        exit;
    }

    $score   = (int)($data['score'] ?? 0);
    $metrics = (array)($data['metrics'] ?? []);

    // Mindestwerte (gegen "Instant-Bot")
    $dur  = (int)($metrics['durationMs'] ?? 0);
    $dist = (float)($metrics['distance'] ?? 0);
    $ev   = (int)($metrics['events'] ?? 0);

    if ($score < 85) {
        echo json_encode(['ok' => false, 'error' => 'Score too low']);
        exit;
    }
    if ($dur < 1200 || $ev < 20 || $dist < 300) {
        echo json_encode(['ok' => false, 'error' => 'Not enough interaction']);
        exit;
    }

    $res = authly_captcha_issue_token($score, [
        'durationMs' => $dur,
        'distance'   => (int)$dist,
        'events'     => $ev,
        'ua'         => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200),
        'ip'         => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64),
    ]);

    echo json_encode($res);
    exit;
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Authly Captcha</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <script src="https://cdn.tailwindcss.com"></script>

    <style>
        body { background:#0b1220; }
        .card {
            background: rgba(17,24,39,0.75);
            border: 1px solid rgba(255,255,255,0.08);
            backdrop-filter: blur(10px);
        }
        .canvas-wrap {
            position: relative;
            width: 100%;
            height: 260px;
            border-radius: 16px;
            overflow: hidden;
            border: 1px solid rgba(255,255,255,0.08);
            background: radial-gradient(1200px 300px at 20% 20%, rgba(124,58,237,0.25), transparent 45%),
                        radial-gradient(1200px 300px at 80% 80%, rgba(59,130,246,0.18), transparent 40%),
                        rgba(0,0,0,0.25);
        }
        canvas { position:absolute; inset:0; width:100%; height:100%; }
        .glow-dot {
            width: 10px; height: 10px; border-radius: 999px;
            background: rgba(124,58,237,0.9);
            box-shadow: 0 0 25px rgba(124,58,237,0.55);
        }
        /* Prevent text selection while moving */
        * { user-select: none; }
        input, textarea { user-select: text; }
    </style>
</head>

<body class="text-gray-200 min-h-screen flex items-center justify-center p-6">

<div class="w-full max-w-2xl card rounded-2xl p-6 shadow-xl space-y-5">
    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="text-xl font-semibold text-gray-100">Verify you’re human</h1>
            <p class="text-sm text-gray-400">Move your mouse (or touch) inside the area. Dots will fade out.</p>
        </div>
        <div class="flex items-center gap-2">
            <div class="glow-dot"></div>
            <span class="text-xs text-gray-400">Authly Captcha</span>
        </div>
    </div>

    <div class="canvas-wrap" id="zone">
        <canvas id="c"></canvas>
    </div>

    <div class="space-y-2">
        <div class="flex items-center justify-between text-xs text-gray-400">
            <span id="status">Verifying…</span>
            <span id="pct">0%</span>
        </div>

        <div class="w-full h-2 bg-gray-700/70 rounded">
            <div id="bar" class="h-2 rounded bg-violet-500" style="width:0%"></div>
        </div>

        <div class="flex items-center justify-between gap-3 pt-2">
            <button id="btnVerify"
                    class="px-4 py-2 rounded-lg bg-gray-700/60 hover:bg-gray-700 text-xs disabled:opacity-40 disabled:cursor-not-allowed"
                    disabled>
                Verify
            </button>
        </div>
    </div>

    <div class="text-xs text-gray-500">
        Token wird serverseitig in der Session gespeichert (2 Minuten gültig, one-time).
    </div>
</div>

<script>
(() => {
    const zone = document.getElementById('zone');
    const canvas = document.getElementById('c');
    const ctx = canvas.getContext('2d');

    const bar = document.getElementById('bar');
    const pct = document.getElementById('pct');
    const status = document.getElementById('status');
    const btnVerify = document.getElementById('btnVerify');

    let w = 0, h = 0, dpr = 1;

    function resize() {
        dpr = Math.max(1, window.devicePixelRatio || 1);
        const r = zone.getBoundingClientRect();
        w = Math.floor(r.width);
        h = Math.floor(r.height);
        canvas.width  = Math.floor(w * dpr);
        canvas.height = Math.floor(h * dpr);
        canvas.style.width = w + 'px';
        canvas.style.height = h + 'px';
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    }

    window.addEventListener('resize', resize);
    resize();

    // Metrics
    let started = false;
    let startTs = 0;
    let lastX = null, lastY = null, lastT = null;
    let distance = 0;
    let events = 0;
    let jitterSum = 0;
    let speedVar = 0;
    let score = 0;
    let verified = false;

    // Visual particles (static trail circles)
    const dots = [];
    const maxDots = 220;

    function clamp(n,a,b){ return Math.max(a, Math.min(b,n)); }

    function calcScore(now) {
        const dur = now - startTs;
        const durScore    = clamp(dur / 2500, 0, 1) * 35;
        const distScore   = clamp(distance / 2200, 0, 1) * 35;
        const evScore     = clamp(events / 120, 0, 1) * 20;
        const jitterScore = clamp(jitterSum / 800, 0, 1) * 10;
        return Math.round(clamp(durScore + distScore + evScore + jitterScore, 0, 100));
    }

    function updateUI() {
        bar.style.width = score + '%';
        pct.textContent = score + '%';
        status.textContent = verified ? 'Verified ✅' : 'Verifying…';
        btnVerify.disabled = !(score >= 95);
    }

    const palette = [
        'rgba(59,130,246,ALPHA)',   // blue
        'rgba(239,68,68,ALPHA)',    // red
        'rgba(236,72,153,ALPHA)',   // pink
        'rgba(34,211,238,ALPHA)',   // cyan
        'rgba(124,58,237,ALPHA)'    // violet
    ];

    function colorFromIndex(idx, alpha) {
        const base = palette[idx % palette.length];
        return base.replace('ALPHA', String(alpha));
    }

    function addDot(x, y) {
        const life = 900 + Math.random() * 900; // ms
        dots.push({
            x, y,
            r: 10, // ✅ alle gleich groß
            born: performance.now(),
            life,
            colorIndex: (Math.random() * palette.length) | 0
        });
        if (dots.length > maxDots) dots.splice(0, dots.length - maxDots);
    }

    function onMove(x, y) {
        const now = performance.now();

        if (!started) {
            started = true;
            startTs = now;
            lastX = x; lastY = y; lastT = now;
        }

        const dx = x - lastX;
        const dy = y - lastY;
        const dt = Math.max(1, now - lastT);

        const step = Math.sqrt(dx*dx + dy*dy);
        distance += step;

        jitterSum += Math.min(10, Math.abs(dx) + Math.abs(dy));

        const speed = step / dt;
        speedVar += Math.abs(speed - 0.35);

        events += 1;

        // ✅ static dot at old position
        addDot(x, y);

        lastX = x; lastY = y; lastT = now;

        score = calcScore(now);
        verified = score >= 95;
        updateUI();
    }

    function draw() {
        const now = performance.now();
        ctx.clearRect(0,0,w,h);

        // subtle grid
        ctx.globalAlpha = 0.10;
        ctx.strokeStyle = '#ffffff';
        ctx.lineWidth = 1;
        const gstep = 28;
        for (let gx=0; gx<w; gx+=gstep) { ctx.beginPath(); ctx.moveTo(gx,0); ctx.lineTo(gx,h); ctx.stroke(); }
        for (let gy=0; gy<h; gy+=gstep) { ctx.beginPath(); ctx.moveTo(0,gy); ctx.lineTo(w,gy); ctx.stroke(); }
        ctx.globalAlpha = 1;

        // dots fade out
        for (let i=dots.length-1; i>=0; i--) {
            const d = dots[i];
            const age = now - d.born;
            const t = age / d.life;
            if (t >= 1) {
                dots.splice(i,1);
                continue;
            }

            const alpha = (1 - t);

            // glow
            ctx.beginPath();
            ctx.fillStyle = colorFromIndex(d.colorIndex, 0.18 * alpha);
            ctx.arc(d.x, d.y, d.r * 2.0, 0, Math.PI * 2);
            ctx.fill();

            // core
            ctx.beginPath();
            ctx.fillStyle = colorFromIndex(d.colorIndex, 0.65 * alpha);
            ctx.arc(d.x, d.y, d.r, 0, Math.PI * 2);
            ctx.fill();

            // highlight
            ctx.beginPath();
            ctx.fillStyle = `rgba(255,255,255,${0.14 * alpha})`;
            ctx.arc(d.x - 3, d.y - 3, d.r * 0.35, 0, Math.PI * 2);
            ctx.fill();
        }

        requestAnimationFrame(draw);
    }
    requestAnimationFrame(draw);

    function relPos(e) {
        const r = zone.getBoundingClientRect();
        return {
            x: clamp(e.clientX - r.left, 0, r.width),
            y: clamp(e.clientY - r.top, 0, r.height)
        };
    }

    zone.addEventListener('mousemove', (e) => {
        const p = relPos(e);
        onMove(p.x, p.y);
    }, { passive:true });

    zone.addEventListener('touchmove', (e) => {
        const t = e.touches && e.touches[0];
        if (!t) return;
        const r = zone.getBoundingClientRect();
        const x = clamp(t.clientX - r.left, 0, r.width);
        const y = clamp(t.clientY - r.top, 0, r.height);
        onMove(x, y);
    }, { passive:true });

    btnVerify.addEventListener('click', async () => {
    btnVerify.disabled = true;
    status.textContent = 'Verifying…';
    status.classList.remove('text-red-400', 'text-green-400');
    status.classList.add('text-gray-400');

    const now = performance.now();
    const durationMs = started ? Math.round(now - startTs) : 0;

    const payload = {
        score: score,
        metrics: {
            durationMs,
            distance: Math.round(distance),
            events: events,
            speedVar: Math.round(speedVar * 100) / 100
        }
    };

    try {
        const res = await fetch('index.php?action=verify', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const j = await res.json();

        if (!j.ok) {
            status.textContent = (j.error || 'Captcha failed');
            status.classList.remove('text-gray-400', 'text-green-400');
            status.classList.add('text-red-400');
            btnVerify.disabled = false;
            return;
        }

        // ✅ Kein alert – nur UI-Update
        status.textContent = 'Captcha OK ✅ (Session Token gesetzt)';
        status.classList.remove('text-gray-400', 'text-red-400');
        status.classList.add('text-green-400');

        // optional: Button bleibt aus, weil erfolgreich
        btnVerify.disabled = true;

    } catch (err) {
        status.textContent = 'Network error';
        status.classList.remove('text-gray-400', 'text-green-400');
        status.classList.add('text-red-400');
        btnVerify.disabled = false;
    }
});


    updateUI();
})();
</script>

</body>
</html>
